<?php

/**
 * Dev-only Step 9 pass-3 REPRODUCIBLE audit runner (NOT a committed test). Orchestrates the strict comparator over
 * a reproduced baseline vs candidate, both built by the FULL Coordinator (real maps/overrides/discovery/config),
 * each in its OWN process so every SpsNext\ FQCN resolves to its own tree's body.
 *
 *   php step9_pass3_runner.php [<baselineTree> <candidateTree>]
 *       [--allowlist=step9_pass3_allowlist.json] [--dump=diffs.json] [--report=report.md] [--manifest=manifest.json]
 *
 * Baseline  = the Legacy swagger-php spec of the BASELINE N tree (must be at 963626d6c) — Coordinator Legacy mode.
 * Candidate = the Metadata-graph spec of the CANDIDATE N tree (N HEAD) built by the CURRENT F engine — Coordinator
 *             Metadata mode. The policy sidecar (StandardErrorPolicy responsesFor) is emitted alongside.
 *
 * It verifies the exact revisions, snapshots BOTH trees' real .cache before/after (proving no touch), runs the
 * strict semantic_diff comparator, applies the machine allowlist (unexplained=0/stale=0/duplicates=0 gate), and
 * writes an audit manifest (commit SHAs + doc SHA-256 + gate). The build_doc helper uses only throwaway temp caches.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

ini_set('memory_limit', '2048M');

$spsfwRoot = dirname(__DIR__, 2);
require $spsfwRoot . '/vendor/autoload.php'; // symfony/yaml for the comparator + YAML parse
define('AUDIT_COMPARATOR_AS_LIBRARY', true);
require __DIR__ . '/semantic_diff_audit.php';

$expectedBaseline = '963626d6c2329543bdd3ee53b8ee19d224a1335c';
$expectedCandidate = '818cff260295e3f7a73db103f82b535626275eae';

$defaultBaseline = '/home/tixlag/PhpstormProjects/.wt/lk-step6b-baseline/next';
$defaultCandidate = '/home/tixlag/PhpstormProjects/.wt/lk-step6b/next';

// ---- args ----
$positional = [];
$allowlistPath = null;
$dumpPath = null;
$reportPath = null;
$manifestPath = null;
$relaxRev = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--allowlist=')) { $allowlistPath = substr($arg, strlen('--allowlist=')); }
    elseif (str_starts_with($arg, '--dump=')) { $dumpPath = substr($arg, strlen('--dump=')); }
    elseif (str_starts_with($arg, '--report=')) { $reportPath = substr($arg, strlen('--report=')); }
    elseif (str_starts_with($arg, '--manifest=')) { $manifestPath = substr($arg, strlen('--manifest=')); }
    elseif ($arg === '--relax-rev') { $relaxRev = true; }
    else { $positional[] = $arg; }
}
$baselineTree = $positional[0] ?? $defaultBaseline;
$candidateTree = $positional[1] ?? $defaultCandidate;
$buildDoc = __DIR__ . '/step9_pass3_build_doc.php';

$fHead = trim((string) shell_exec('git -C ' . escapeshellarg($spsfwRoot) . ' rev-parse HEAD 2>/dev/null'));

echo "==== Step 9 pass-3 semantic audit ====\n";
echo "F engine HEAD: $fHead\n";
echo "baseline tree:  $baselineTree\n";
echo "candidate tree: $candidateTree\n";
echo "expected baseline @ {$expectedBaseline} (963626d6c)\n";
echo "expected candidate @ {$expectedCandidate} (N HEAD 818cff260)\n\n";

// ============================================================================
// 1. Rev verification.
// ============================================================================
$bHead = trim((string) shell_exec('git -C ' . escapeshellarg($baselineTree) . ' rev-parse HEAD 2>/dev/null'));
$cHead = trim((string) shell_exec('git -C ' . escapeshellarg($candidateTree) . ' rev-parse HEAD 2>/dev/null'));
$revOk = ($bHead === $expectedBaseline) && ($cHead === $expectedCandidate);
echo "revs: baseline=" . substr($bHead, 0, 10) . " candidate=" . substr($cHead, 0, 10) . " => " . ($revOk ? 'MATCH' : 'MISMATCH') . "\n";
if (!$revOk && !$relaxRev) {
    fwrite(STDERR, "ABORT: revision mismatch (override with --relax-rev only for local experiments)\n");
    exit(2);
}

// ============================================================================
// 2. Snapshot BOTH trees' real .cache before (proves the audit is read-only w.r.t. live caches).
// ============================================================================
$snapshotCache = static function (string $treeRoot): array {
    $dir = $treeRoot . '/.cache';
    $hashes = [];
    if (!is_dir($dir)) {
        return $hashes;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && !preg_match('#^(?:\.compile\.lock$|\.staging-|\.backup-)#', substr($f->getPathname(), strlen($dir) + 1))) {
            $hashes[substr($f->getPathname(), strlen($dir) + 1)] = md5_file($f->getPathname());
        }
    }
    ksort($hashes);
    return $hashes;
};
$cacheBefore = ['baseline' => $snapshotCache($baselineTree), 'candidate' => $snapshotCache($candidateTree)];

// ============================================================================
// 3. Build both docs (separate processes, throwaway temp caches).
// ============================================================================
$tmpBase = sys_get_temp_dir() . '/p3_base_' . bin2hex(random_bytes(3)) . '.yml';
$tmpCand = sys_get_temp_dir() . '/p3_cand_' . bin2hex(random_bytes(3)) . '.yml';
$tmpPolicy = sys_get_temp_dir() . '/p3_policy_' . bin2hex(random_bytes(3)) . '.json';
$tmpBaseMeta = sys_get_temp_dir() . '/p3_basemeta_' . bin2hex(random_bytes(3)) . '.json';
$tmpCandMeta = sys_get_temp_dir() . '/p3_candmeta_' . bin2hex(random_bytes(3)) . '.json';
$cleanup = static function () use ($tmpBase, $tmpCand, $tmpPolicy, $tmpBaseMeta, $tmpCandMeta): void {
    foreach ([$tmpBase, $tmpCand, $tmpPolicy, $tmpBaseMeta, $tmpCandMeta] as $f) { @unlink($f); }
};

$runBuild = static function (string $tree, string $mode, string $outYml, ?string $outPolicy, string $outMeta): array {
    global $buildDoc;
    $cmd = sprintf('php %s %s %s %s %s %s 2>&1', escapeshellarg($buildDoc), escapeshellarg($tree), $mode, escapeshellarg($outYml), escapeshellarg((string) $outPolicy), escapeshellarg($outMeta));
    exec($cmd, $out, $code);
    return ['exit' => $code, 'log' => implode("\n", $out)];
};

$abort = static function (string $msg, int $code) use ($cleanup): void {
    $cleanup();
    fwrite(STDERR, "ABORT: $msg\n");
    exit($code);
};

echo "\n-- building baseline (legacy) --\n";
$b = $runBuild($baselineTree, 'legacy', $tmpBase, null, $tmpBaseMeta);
echo $b['log'] . "\n";
if ($b['exit'] !== 0) { $abort('baseline build failed (exit ' . $b['exit'] . ')', 3); }

echo "-- building candidate (metadata) --\n";
$c = $runBuild($candidateTree, 'metadata', $tmpCand, $tmpPolicy, $tmpCandMeta);
echo $c['log'] . "\n";
if ($c['exit'] !== 0) { $abort('candidate build failed (exit ' . $c['exit'] . ')', 3); }

$baseMeta = json_decode((string) file_get_contents($tmpBaseMeta), true);
$candMeta = json_decode((string) file_get_contents($tmpCandMeta), true);
if (!is_array($baseMeta) || !is_array($candMeta)) { $abort('build meta JSON missing', 3); }

// ============================================================================
// 4. Real .cache immutability.
// ============================================================================
$cacheAfter = ['baseline' => $snapshotCache($baselineTree), 'candidate' => $snapshotCache($candidateTree)];
$cacheImmutable = ($cacheBefore === $cacheAfter);
echo "\nreal .cache byte-unchanged (both trees): " . ($cacheImmutable ? 'YES' : 'NO') . "\n";

// ============================================================================
// 5. Strict semantic diff + allowlist gate.
// ============================================================================
$baseDoc = Yaml::parseFile($tmpBase);
$candDoc = Yaml::parseFile($tmpCand);
$diffs = semantic_diff(is_array($baseDoc) ? $baseDoc : [], is_array($candDoc) ? $candDoc : []);

if ($dumpPath !== null) {
    file_put_contents($dumpPath, json_encode([
        'baseline_sha256' => $baseMeta['primary_sha256'] ?? null,
        'candidate_sha256' => $candMeta['primary_sha256'] ?? null,
        'diffs' => $diffs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$byCat = [];
foreach ($diffs as $d) { $byCat[$d['category']] = ($byCat[$d['category']] ?? 0) + 1; }
$order = ['OP_ONLY_BASE', 'OP_ONLY_CAND', 'PARAM_DUP', 'OP_FIELD', 'SECURITY', 'PARAM', 'REQUEST_BODY', 'RESPONSE'];

echo "\n===== raw semantic diffs (" . count($diffs) . ") =====\n";
foreach ($order as $cat) {
    if (($byCat[$cat] ?? 0) > 0) { echo sprintf("  %-14s %d\n", $cat, $byCat[$cat]); }
}

$gate = null;
$gatePass = null;
if ($allowlistPath !== null && is_file($allowlistPath)) {
    $allowlist = json_decode((string) file_get_contents($allowlistPath), true);
    $policy = is_file($tmpPolicy) ? json_decode((string) file_get_contents($tmpPolicy), true) : null;
    $gate = apply_allowlist($diffs, is_array($allowlist) ? $allowlist : [], is_array($policy) ? $policy : null);
    $gatePass = count($gate['unexplained']) === 0 && count($gate['stale']) === 0 && count($gate['duplicates']) === 0;
    echo "\n===== allowlist gate (" . basename($allowlistPath) . ") =====\n";
    echo "  matched         = " . $gate['matched'] . "\n";
    echo "  unexplained     = " . count($gate['unexplained']) . "\n";
    echo "  stale_allowlist = " . count($gate['stale']) . "\n";
    echo "  duplicate_match = " . count($gate['duplicates']) . "\n";
    if (!empty($gate['per_rule'])) {
        echo "  per_rule:\n";
        foreach ($gate['per_rule'] as $rule => $n) { echo sprintf("    %-10s %d\n", $rule, $n); }
    }
    echo "  RESULT: " . ($gatePass ? 'PASS (unexplained=0 stale=0 duplicates=0)' : 'FAIL') . "\n";
} else {
    echo "\n(no --allowlist given: raw diffs only; run with --allowlist once step9_pass3_allowlist.json exists)\n";
}

// ============================================================================
// 6. Manifest.
// ============================================================================
if ($manifestPath !== null) {
    file_put_contents($manifestPath, json_encode([
        'built_at' => date('c'),
        'f_head' => $fHead,
        'baseline' => ['tree' => $baselineTree, 'git_head' => $bHead, 'sha256' => $baseMeta['primary_sha256'] ?? null, 'published' => $baseMeta['published'] ?? null, 'errors' => $baseMeta['errorCount'] ?? null, 'warnings' => $baseMeta['warningCount'] ?? null],
        'candidate' => ['tree' => $candidateTree, 'git_head' => $cHead, 'sha256' => $candMeta['primary_sha256'] ?? null, 'published' => $candMeta['published'] ?? null, 'errors' => $candMeta['errorCount'] ?? null, 'warnings' => $candMeta['warningCount'] ?? null, 'sidecar_ops' => $candMeta['operations_in_sidecar'] ?? null],
        'rev_verified' => $revOk,
        'real_cache_unchanged' => $cacheImmutable,
        'raw_diff_count' => count($diffs),
        'by_category' => $byCat,
        'gate' => $gate,
        'gate_pass' => $gatePass,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo "\n==== summary ====\n";
echo "baseline published=" . ($baseMeta['published'] ? 'YES' : 'NO') . " errors=" . ($baseMeta['errorCount'] ?? '?') . " warnings=" . ($baseMeta['warningCount'] ?? '?') . "\n";
echo "candidate published=" . ($candMeta['published'] ? 'YES' : 'NO') . " errors=" . ($candMeta['errorCount'] ?? '?') . " warnings=" . ($candMeta['warningCount'] ?? '?') . "\n";
echo "raw semantic diffs: " . count($diffs) . "\n";
if ($gatePass !== null) { echo "allowlist gate: " . ($gatePass ? 'PASS' : 'FAIL') . "\n"; }

$cleanup();
exit($gatePass === false ? 1 : 0);

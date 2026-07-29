<?php

/**
 * Dev-only Step 9 pass-3 diff ANALYZER (NOT a committed test). Rebuilds both docs to stable temp paths, runs the
 * strict comparator, and prints a per-category pattern breakdown + representative examples so each diff can be
 * classified FIX (real candidate loss) vs ALLOWLIST (legitimate residual with a rule + proof).
 *
 *   php step9_pass3_analyze.php [<category>] [limit]
 *
 * Rebuilds are cached: if /tmp/p3_base.yml + /tmp/p3_cand.yml exist it reuses them (rm to force rebuild).
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

ini_set('memory_limit', '2048M');
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('AUDIT_COMPARATOR_AS_LIBRARY', true);
require __DIR__ . '/semantic_diff_audit.php';

$base = '/tmp/p3_base.yml';
$cand = '/tmp/p3_cand.yml';
if (!is_file($base) || !is_file($cand)) {
    $bd = __DIR__ . '/step9_pass3_build_doc.php';
    echo "(rebuilding docs)\n";
    exec(sprintf('php %s %s legacy %s 2>/dev/null', escapeshellarg($bd), escapeshellarg('/home/tixlag/PhpstormProjects/.wt/lk-step6b-baseline/next'), escapeshellarg($base)), $o1, $c1);
    exec(sprintf('php %s %s metadata %s /tmp/p3_policy.json 2>/dev/null', escapeshellarg($bd), escapeshellarg('/home/tixlag/PhpstormProjects/.wt/lk-step6b/next'), escapeshellarg($cand)), $o2, $c2);
    if ($c1 !== 0 || $c2 !== 0) { fwrite(STDERR, "rebuild failed\n"); exit(2); }
}

$diffs = semantic_diff(Yaml::parseFile($base), Yaml::parseFile($cand));
$filter = $argv[1] ?? null;
$limit = (int) ($argv[2] ?? 20);

$byCat = [];
foreach ($diffs as $d) { $byCat[$d['category']] = ($byCat[$d['category']] ?? 0) + 1; }
echo "total diffs: " . count($diffs) . "\n";
foreach (['OP_ONLY_BASE', 'OP_ONLY_CAND', 'PARAM_DUP', 'OP_FIELD', 'SECURITY', 'PARAM', 'REQUEST_BODY', 'RESPONSE'] as $c) {
    if (($byCat[$c] ?? 0) > 0) { echo sprintf("  %-13s %d\n", $c, $byCat[$c]); }
}

// ---- RESPONSE status breakdown (R1 vs success) ----
if ($filter === null) {
    $resp = array_values(array_filter($diffs, fn ($x) => $x['category'] === 'RESPONSE'));
    $r1additive = count(array_filter($resp, fn ($x) => $x['candidate_is_error'] && $x['baseline_empty']));
    $r1content = count(array_filter($resp, fn ($x) => $x['candidate_is_error'] && !$x['baseline_empty']));
    $succAdd = count(array_filter($resp, fn ($x) => !$x['candidate_is_error'] && $x['baseline_empty']));
    $succContent = count(array_filter($resp, fn ($x) => !$x['candidate_is_error'] && !$x['baseline_empty']));
    echo "\nRESPONSE (" . count($resp) . "): R1-additive(candidate error, baseline empty)=" . $r1additive
        . " R1-content(candidate error, baseline non-empty)=" . $r1content
        . " success-additive=" . $succAdd . " success-content=" . $succContent . "\n";
}

// ---- detailed listing for a single category (RESPONSE accepts a sub-type as the 3rd arg) ----
if ($filter !== null) {
    $sub = $argv[2] ?? null;
    $limit = (int) ($argv[3] ?? 20);
    $rows = array_values(array_filter($diffs, fn ($x) => $x['category'] === $filter));
    if ($filter === 'RESPONSE' && $sub !== null && !ctype_digit($sub)) {
        $rows = array_values(array_filter($rows, function ($x) use ($sub): bool {
            $ce = (bool) $x['candidate_is_error'];
            $be = (bool) $x['baseline_empty'];
            return match ($sub) {
                'r1additive' => $ce && $be,
                'r1content' => $ce && !$be,
                'success-additive' => !$ce && $be,
                'success-content' => !$ce && !$be,
                default => true,
            };
        }));
        echo "\n===== RESPONSE :: $sub (up to $limit) =====\n";
    } else {
        $limit = (int) ($argv[2] ?? 20);
        echo "\n===== $filter (up to $limit) =====\n";
    }
    foreach (array_slice($rows, 0, $limit) as $x) {
        $b = $x['baseline_fragment'];
        $c = $x['candidate_fragment'];
        $bS = is_array($b) ? substr(json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 240) : var_export($b, true);
        $cS = is_array($c) ? substr(json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 240) : var_export($c, true);
        $flags = '';
        if ($x['status']) { $flags .= " status={$x['status']}"; }
        if ($x['candidate_is_error']) { $flags .= ' candErr'; }
        if ($x['baseline_empty']) { $flags .= ' baseEmpty'; }
        echo "• {$x['method_path']} @ {$x['pointer']}{$flags}\n";
        echo "    base: $bS\n";
        echo "    cand: $cS\n";
    }
    echo "(" . count($rows) . " total)\n";
}

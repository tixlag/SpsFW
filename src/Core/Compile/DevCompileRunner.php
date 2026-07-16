<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

use SpsFW\Core\Router\PathManager;

/**
 * Dev-only convenience wrapper over the {@see Coordinator} (plan §11.1, Step 5).
 *
 * It has NO build logic of its own: it resolves a sensible {@see ApplicationContext} from {@see PathManager}
 * defaults, hands it to a {@see Coordinator}, runs the single compile flow, and renders a human-readable report.
 * It deliberately does NOT load env / dynamic config / Config::init / DI bindings — those remain the production
 * owner's job (the client `preload.php`, plan §11.2). Use this for ad-hoc local builds and probes; do not call it
 * from the production request path (the real owner wires its own ApplicationContext and calls Coordinator directly).
 *
 * Example (CLI): `php bin/spsfw-compile.php --dry-run --mode=managed --policy=parity`
 */
final class DevCompileRunner
{
    private ?Coordinator $coordinator = null;

    private ?CompileResult $result = null;

    /**
     * Build from PathManager defaults and run the Coordinator.
     *
     * @param array{
     *     dryRun?: bool,
     *     mode?: string,
     *     diagnosticPolicy?: string,
     *     cachePath?: string,
     *     discoveryPaths?: ?list<string>,
     *     configInputs?: array<string, mixed>,
     * } $options
     */
    public function execute(array $options = []): self
    {
        $mode = $options['mode'] ?? ApplicationContext::MODE_LEGACY;
        $policy = $options['diagnosticPolicy'] ?? ApplicationContext::POLICY_PARITY;

        // configInputs are part of the fingerprint; record the resolved mode/policy plus any caller-supplied inputs
        // (openapi title/version, escape-hatch config, operation-id lock, …) so the manifest reflects the build.
        $configInputs = array_merge(
            ['mode' => $mode, 'diagnostic_policy' => $policy],
            $options['configInputs'] ?? [],
        );

        $context = new ApplicationContext(
            projectRoot: PathManager::getProjectRoot(),
            cachePath: $options['cachePath'] ?? PathManager::getCachePath(),
            discoveryPaths: $options['discoveryPaths'] ?? PathManager::getControllersDirs(),
            configInputs: $configInputs,
            mode: $mode,
            diagnosticPolicy: $policy,
        );

        $this->coordinator = new Coordinator($context);
        $this->result = $this->coordinator->compile(dryRun: (bool) ($options['dryRun'] ?? false));

        return $this;
    }

    public function result(): CompileResult
    {
        return $this->result ?? throw new \LogicException('DevCompileRunner::execute() has not been called yet.');
    }

    public function diagnostics(): CompileDiagnostics
    {
        return $this->coordinator?->diagnostics()
            ?? throw new \LogicException('DevCompileRunner::execute() has not been called yet.');
    }

    /**
     * A concise human-readable report: the publication outcome, the diagnostic counts, and (if any) the rendered
     * diagnostics (errors first, then warnings). Intended for CLI / probe output.
     */
    public function render(): string
    {
        $result = $this->result();
        $diag = $this->diagnostics();

        $lines = [];
        $lines[] = sprintf('mode=%s policy=%s', $this->coordinator->context()->mode, $this->coordinator->context()->diagnosticPolicy);
        $lines[] = sprintf('errors=%d warnings=%d', $result->errorCount, $result->warningCount);

        if ($result->published) {
            $lines[] = sprintf('PUBLISHED %d artifact(s); manifest=%s', count($result->artifacts), $result->manifestPath);
        } else {
            $lines[] = sprintf('NOT PUBLISHED (reason=%s%s)', $result->reason, $result->dryRun ? '; dry-run' : '');
        }
        if ($result->fingerprint !== null) {
            $lines[] = sprintf('fingerprint=%s', $result->fingerprint);
        }
        if ($diag->count() > 0) {
            $lines[] = '';
            $lines[] = $diag->render();
        }

        return implode("\n", $lines);
    }

    /**
     * CLI entry point. Parses a tiny flag set, runs the coordinator, prints the report, and returns a process exit
     * code (0 on a clean publish or a clean dry run; non-zero when publication was blocked by diagnostics).
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $options = ['dryRun' => false, 'mode' => ApplicationContext::MODE_LEGACY, 'diagnosticPolicy' => ApplicationContext::POLICY_PARITY];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--dry-run') {
                $options['dryRun'] = true;
            } elseif ($arg === '--managed') {
                $options['mode'] = ApplicationContext::MODE_MANAGED;
            } elseif ($arg === '--strict') {
                $options['diagnosticPolicy'] = ApplicationContext::POLICY_STRICT;
            } elseif (str_starts_with($arg, '--mode=')) {
                $options['mode'] = substr($arg, strlen('--mode='));
            } elseif (str_starts_with($arg, '--policy=')) {
                $options['diagnosticPolicy'] = substr($arg, strlen('--policy='));
            }
        }

        $runner = (new self())->execute($options);
        echo $runner->render() . "\n";

        $result = $runner->result();
        // A clean publish, or any dry run (read-only probe), exits 0; a blocked publication exits non-zero.
        if ($result->published || $result->dryRun) {
            return 0;
        }
        return 1;
    }
}

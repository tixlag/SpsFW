<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

use SpsFW\Core\Config;
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
 * Example (CLI): `php bin/spsfw-compile.php --mode=managed --policy=parity` (default = dry-run; add `--publish` to
 * write, which requires an application bootstrap — see {@see main()}).
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
     *     operationIdMap?: array<string, ?string>,
     *     routeOverrideMap?: array<string, string>,
     *     configFiles?: array<string, string>,
     *     lockTimeoutSec?: float,
     * } $options
     */
    public function execute(array $options = []): self
    {
        $mode = $options['mode'] ?? ApplicationContext::MODE_LEGACY;
        $policy = $options['diagnosticPolicy'] ?? ApplicationContext::POLICY_PARITY;

        // configInputs are part of the fingerprint; record the resolved mode/policy plus any caller-supplied inputs
        // (openapi title/version, escape-hatch config, …) so the manifest reflects the build. Secrets-bearing config
        // files go into configFiles (content-hashed, never stored wholesale), NOT here.
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
            operationIdMap: $options['operationIdMap'] ?? [],
            routeOverrideMap: $options['routeOverrideMap'] ?? [],
            configFiles: $options['configFiles'] ?? [],
            lockTimeoutSec: $options['lockTimeoutSec'] ?? 0.0,
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
     * code.
     *
     * Contract (Step 5 fix-pass):
     *   - DEFAULT is a DRY-RUN (read-only: build + validate + report). Publication requires the explicit `--publish`
     *     flag — a generic framework CLI must not silently overwrite an application's cache.
     *   - `--publish` is REFUSED unless the application bootstrapped (Config::isBootstrapped()): the engine must not
     *     publish a DI cache built without the application's DI bindings. Run the Coordinator from your application
     *     preload (which calls Config::init / setDIBindings) to publish for real.
     *   - EXIT CODE: a clean build exits 0; an ERROR exits non-zero (even in a dry run); under `--strict` a WARNING
     *     also exits non-zero. A refused `--publish` exits 2.
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $publish = false;
        $mode = ApplicationContext::MODE_LEGACY;
        $policy = ApplicationContext::POLICY_PARITY;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--publish') {
                $publish = true;
            } elseif ($arg === '--dry-run') {
                $publish = false;
            } elseif ($arg === '--managed') {
                $mode = ApplicationContext::MODE_MANAGED;
            } elseif ($arg === '--strict') {
                $policy = ApplicationContext::POLICY_STRICT;
            } elseif (str_starts_with($arg, '--mode=')) {
                $mode = substr($arg, strlen('--mode='));
            } elseif (str_starts_with($arg, '--policy=')) {
                $policy = substr($arg, strlen('--policy='));
            }
        }

        // The generic framework CLI must NOT publish a DI cache without application bootstrap/DI bindings.
        if ($publish && !Config::isBootstrapped()) {
            fwrite(STDERR, "Publication refused: the framework CLI has no application bootstrap (Config::init / DI bindings did not run). Run the Coordinator from your application preload, or bootstrap before invoking --publish.\n");
            return 2;
        }

        $options = ['dryRun' => !$publish, 'mode' => $mode, 'diagnosticPolicy' => $policy];
        $runner = (new self())->execute($options);
        echo $runner->render() . "\n";

        // Exit code is driven by diagnostics: ERROR ⇒ non-zero (always, even dry-run); strict + WARNING ⇒ non-zero.
        $result = $runner->result();
        if ($result->errorCount > 0) {
            return 1;
        }
        if ($policy === ApplicationContext::POLICY_STRICT && $result->warningCount > 0) {
            return 1;
        }
        return 0;
    }
}

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
     *     mode?: string|\SpsFW\Core\Compile\CompileMode,
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
        // Resolve the mode through the SINGLE explicit-error resolution point: a non-empty invalid value throws here
        // (never silently Legacy). Accept an already-resolved CompileMode too.
        $modeRaw = $options['mode'] ?? CompileMode::Legacy;
        $mode = $modeRaw instanceof CompileMode ? $modeRaw : CompileMode::fromString((string) $modeRaw);
        $policy = $options['diagnosticPolicy'] ?? ApplicationContext::POLICY_PARITY;

        // Caller-supplied scalar config inputs (openapi title/version, escape-hatch config, …) flow straight into the
        // fingerprint. Mode and diagnostic policy are NOT merged here: the resolved mode is passed as the typed `mode`
        // field below, and the Coordinator injects BOTH (from the typed fields) into the recorded config so the
        // manifest always reflects them. Secrets-bearing config files go into configFiles (content-hashed, never
        // stored wholesale), NOT here.
        $configInputs = $options['configInputs'] ?? [];

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
        $lines[] = sprintf('mode=%s policy=%s', $this->coordinator->context()->mode->value, $this->coordinator->context()->diagnosticPolicy);
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
     *   - DEFAULT is a DRY-RUN (read-only: build + validate + report). The generic framework CLI is ALWAYS read-only:
     *     a DI cache must be built WITH the application's DI bindings (Config::init + Config::setDIBindings), which only
     *     the application owner runs (`next/preload.php`, plan §11.2, Step 6b). `--publish` is therefore ALWAYS refused
     *     here (exit 2) — it never publishes, regardless of any bootstrap. To publish for real, wire your own
     *     ApplicationContext in your preload and call Coordinator::compile() directly.
     *   - EXIT CODE: a clean (dry-run) build exits 0; an ERROR exits non-zero (even in a dry run); under `--strict` a
     *     WARNING also exits non-zero. `--publish` always exits 2.
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $publish = false;
        $mode = CompileMode::Legacy;
        $policy = ApplicationContext::POLICY_PARITY;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--publish') {
                $publish = true;
            } elseif ($arg === '--dry-run') {
                $publish = false;
            } elseif ($arg === '--managed') {
                $mode = CompileMode::Managed;
            } elseif ($arg === '--strict') {
                $policy = ApplicationContext::POLICY_STRICT;
            } elseif (str_starts_with($arg, '--mode=')) {
                $mode = CompileMode::fromString(substr($arg, strlen('--mode=')));
            } elseif (str_starts_with($arg, '--policy=')) {
                $policy = substr($arg, strlen('--policy='));
            }
        }

        // The generic framework CLI is READ-ONLY: it never publishes a DI cache. A real build needs the application's
        // DI bindings (Config::init + setDIBindings), which the application preload owns (plan §11.2, Step 6b). Always
        // exit 2 and point at the Coordinator-in-preload path.
        if ($publish) {
            fwrite(STDERR, "Publication refused: the framework CLI is read-only. Run the Coordinator from your application preload (Config::init + Config::setDIBindings, then Coordinator::compile()) — that is the production publish path.\n");
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

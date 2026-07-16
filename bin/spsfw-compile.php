<?php

/**
 * Dev-only CLI entry point for the SpsFW metadata compiler (plan §11.1, Step 5).
 *
 * Thin wrapper — ALL build logic lives in {@see \SpsFW\Core\Compile\Coordinator}; this script only loads autoload
 * and delegates to {@see \SpsFW\Core\Compile\DevCompileRunner::main()}. The generic framework CLI is ALWAYS
 * READ-ONLY: it never publishes a DI cache, because a real build needs the application's DI bindings
 * (Config::init + Config::setDIBindings), which only the application owner runs. For a production build, wire your
 * own ApplicationContext in your preload and call Coordinator directly (plan §11.2, Step 6b).
 *
 * Usage:
 *   php bin/spsfw-compile.php                 # DRY-RUN (default): build + validate + report, write nothing
 *   php bin/spsfw-compile.php --publish        # ALWAYS refused (exit 2) — the CLI is read-only; publish via preload
 *   php bin/spsfw-compile.php --managed --strict
 *
 * Flags: --publish, --dry-run, --managed, --strict, --mode=legacy|managed, --policy=parity|strict
 *
 * Exit code: 0 on a clean dry-run build; 1 on an ERROR (always), or under --strict on a WARNING;
 * 2 when --publish is requested (always refused — the CLI never publishes).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

exit(\SpsFW\Core\Compile\DevCompileRunner::main($argv));

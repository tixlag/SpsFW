<?php

/**
 * Dev-only CLI entry point for the SpsFW metadata compiler (plan §11.1, Step 5).
 *
 * Thin wrapper — ALL build logic lives in {@see \SpsFW\Core\Compile\Coordinator}; this script only loads autoload
 * and delegates to {@see \SpsFW\Core\Compile\DevCompileRunner::main()}. It does NOT bootstrap env/config/DI
 * bindings: for a real production build, wire your own ApplicationContext in your preload and call Coordinator
 * directly (plan §11.2).
 *
 * Usage:
 *   php bin/spsfw-compile.php                 # DRY-RUN (default): build + validate + report, write nothing
 *   php bin/spsfw-compile.php --publish        # publish — REFUSED without an application bootstrap (exit 2)
 *   php bin/spsfw-compile.php --managed --strict
 *
 * Flags: --publish, --dry-run, --managed, --strict, --mode=legacy|managed, --policy=parity|strict
 *
 * Exit code: 0 on a clean build (publish or dry-run); 1 on an ERROR (always), or under --strict on a WARNING;
 * 2 when --publish is refused (no application bootstrap).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

exit(\SpsFW\Core\Compile\DevCompileRunner::main($argv));

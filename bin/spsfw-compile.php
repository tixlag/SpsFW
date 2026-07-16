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
 *   php bin/spsfw-compile.php                 # legacy + parity, publish to .cache/
 *   php bin/spsfw-compile.php --dry-run        # read-only: build + validate + report, write nothing
 *   php bin/spsfw-compile.php --managed --strict
 *
 * Flags: --dry-run, --managed, --strict, --mode=legacy|managed, --policy=parity|strict
 *
 * Exit code: 0 on a clean publish OR any dry run (read-only probe); non-zero when publication was blocked by
 * diagnostics.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

exit(\SpsFW\Core\Compile\DevCompileRunner::main($argv));

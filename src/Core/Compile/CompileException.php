<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

/**
 * Raised on a fatal compile-time failure, or by {@see CompileDiagnostics::throwOnErrors()} when the
 * accumulated diagnostics are non-empty.
 *
 * This is a build/programming error, not an HTTP response error: it is observed by the compilation
 * owner (the client preload / CI), never by HTTP runtime. It therefore extends {@see \RuntimeException}
 * rather than the framework's HTTP-coupled BaseException — the compile namespace stays self-contained.
 */
class CompileException extends \RuntimeException
{
}

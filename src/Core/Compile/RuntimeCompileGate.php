<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile;

use SpsFW\Core\Exceptions\BaseException;

/**
 * The runtime explicit-rebuild policy (Step 6a, plan §11.4).
 *
 * An EXPLICIT rebuild action — a forced route rebuild (Router::loadRoutes with createCache=true), an HTTP rebuild
 * endpoint (CoreUtilController), or an independent OpenAPI build (DocsUtil::updateDocs) — is allowed in 'legacy'
 * (always, BC) and in 'managed' ONLY in dev. Outside dev in managed, the preload (Coordinator) owns the artifact
 * set, so these actions are refused with a clear error pointing at the preload.
 *
 * This is the ONLY place that reads the APP_ENV "dev" flag for the rebuild decision, keeping the dev-allowance logic
 * in one spot. (Implicit cache-miss fail-fast in managed — a Router route-cache miss or a missing DI cache — is
 * handled directly at those entrypoints via {@see CompileMode::isManaged()}, NOT here: a missing cache in managed
 * fails fast EVEN in dev, because the preload contract is "the cache exists".)
 */
final class RuntimeCompileGate
{
    /**
     * Allow an explicit runtime rebuild, or throw when it is forbidden (managed, outside dev).
     *
     * @param string $context short label for the error message ("route", "OpenAPI documentation", "DI", …).
     * @throws BaseException when {@see CompileMode} is managed and the environment is not dev.
     */
    public static function assertAllowed(string $context): void
    {
        if (CompileMode::current()->isManaged() && !self::isDev()) {
            throw new BaseException(sprintf(
                'Runtime %s rebuild is forbidden in managed compile mode outside dev. Build via the application preload (Coordinator) instead.',
                $context,
            ));
        }
    }

    /**
     * Whether the application runs in the 'dev' environment (APP_ENV === 'dev'). The sole reader of APP_ENV for the
     * rebuild policy. Defaults to false (production-safe) when APP_ENV is unset or holds any other value.
     */
    public static function isDev(): bool
    {
        $env = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: '');
        return $env === 'dev';
    }
}

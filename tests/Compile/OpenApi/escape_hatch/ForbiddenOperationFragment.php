<?php

/**
 * Escape-hatch fixture: a carrier with a FORBIDDEN operation annotation (#[OA\Get]). Proves the
 * annotation-level guard (EscapeHatchAnalysisGuard) catches a non-schema annotation EVEN THOUGH the
 * schema-preserving pipeline has no BuildPaths processor (so the operation would otherwise vanish from the
 * generated doc and be missed by a shape-only check). The guard FATAL names the annotation type (Get) and the
 * source file/class.
 */

declare(strict_types=1);

namespace SpsOaTest\EscapeHatch;

use OpenApi\Attributes as OA;

class ForbiddenOperationCarrier
{
    #[OA\Get(path: '/escape-hatch/forbidden', description: 'operations are forbidden in the escape hatch')]
    public function forbidden(): void
    {
    }
}

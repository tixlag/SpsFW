<?php

/**
 * Fixture for OpenApiEmitterTest / SchemaNameResolver — three classes that all share the short name
 * `CollideDto` but live in different namespaces. Declared with BRACKETED namespace blocks (a single PHP file
 * must use all-bracketed or all-unbracketed namespaces; the consuming test file is unbracketed global, so the
 * classes live here). Required-once by the test; not auto-discovered (not a *Test.php).
 */

declare(strict_types=1);

namespace SpsOaTest\DupA {
    final class CollideDto
    {
        public string $a = '';
    }
}

namespace SpsOaTest\DupB {
    final class CollideDto
    {
        public string $b = '';
    }
}

namespace SpsOaTest\Renamed {
    use SpsFW\Core\Attributes\OpenApi\Field;

    /** Class-level #[Field(schema:)] overrides the component name the resolver would otherwise assign. */
    #[Field(schema: 'RenamedDto')]
    final class CollideDto
    {
        public string $c = '';
    }
}

<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Introspection;

/**
 * The direction a schema is built for — INPUT (request/query hydration + validation) or OUTPUT (response
 * serialization). One PHP class may serialize a DIFFERENT key set on the wire than it hydrates: a class that
 * implements {@see \JsonSerializable} curates its response shape in {@see \JsonSerializable::jsonSerialize()},
 * typically excluding internal DB columns and credentials. The schema projection must honor that for OUTPUT;
 * for INPUT it stays the exhaustive public-property set the runtime hydrator/validator reads (plan §6).
 *
 *  - {@see Input}  : every public, non-static property (the hydration/validation contract). This is the
 *                    historical {@see DtoSchemaBuilder::build()} behavior — the default, so every existing call
 *                    site is unchanged.
 *  - {@see Output} : the actual response wire shape. For a non-JsonSerializable class this is identical to Input
 *                    (there is no serializer to override the public-property set). For a JsonSerializable class the
 *                    builder projects the statically-proven {@see jsonSerialize()} subset instead — NEVER the
 *                    exhaustive set — so internal/credential fields do not leak into the response contract. When
 *                    the serializer's shape is not statically provable, the builder emits a diagnostic and renders
 *                    no exhaustive fallback (Step 9.5 §2/§3).
 *
 * The builder memo is direction-keyed: the same class may carry a distinct Input and Output projection.
 */
enum SchemaDirection: string
{
    case Input = 'input';
    case Output = 'output';
}

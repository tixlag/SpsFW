<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Clean;

/**
 * Step 5 Coordinator fixture — a plain request DTO (public string property, no OA) exercised by the JsonBody
 * binding of {@see FlowCleanController::create()}. Analyzed by DtoSchemaBuilder (rule graph) + DICacheBuilder.
 */
final class FlowCreateDto
{
    public string $email = '';
}

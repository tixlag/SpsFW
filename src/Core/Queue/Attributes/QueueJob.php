<?php

namespace SpsFW\Core\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class QueueJob
{
    public function __construct(
        public string $name
    ) {}
}
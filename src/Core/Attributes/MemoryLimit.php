<?php

namespace SpsFW\Core\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class MemoryLimit
{
    public function __construct(
        public string $limit
    ) {}
}
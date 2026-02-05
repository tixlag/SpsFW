<?php

namespace SpsFW\Core\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class PhpIni
{
    public function __construct(
        public array $settings = []
    ) {}
}
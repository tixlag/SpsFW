<?php
namespace SpsFW\Core\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class JobHandler
{
    public function __construct(public string $name) {}
}

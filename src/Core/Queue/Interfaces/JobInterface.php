<?php

namespace SpsFW\Core\Queue\Interfaces;

interface JobInterface
{
    public function getName(): string;
    public function serialize(): string;
    public static function deserialize(string $payload): static;
}
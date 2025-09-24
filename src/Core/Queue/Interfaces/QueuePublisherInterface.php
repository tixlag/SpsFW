<?php

namespace SpsFW\Core\Queue\Interfaces;

interface QueuePublisherInterface
{
    public function publish(JobInterface $job): void;
}
<?php
namespace SpsFW\Core\Queue\Interfaces;

use SpsFW\Core\Queue\JobResult;
use SpsFW\Core\Queue\Interfaces\JobInterface;

interface JobHandlerInterface
{
    /**
     * Handle given job and return JobResult
     */
    public function handle(JobInterface $job): JobResult;
}

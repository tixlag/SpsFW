<?php

namespace SpsFW\Core\Queue;

class WorkerExecutionPolicy
{
    public function __construct(
        public int $jobTimeoutSec = 120,
        public int $maxRetries = 3,
        public bool $enableDlq = true,
        public int $prefetch = 1,
        public int $heartbeatSec = 30
    ) {
        $this->jobTimeoutSec = max(0, $this->jobTimeoutSec);
        $this->maxRetries = max(0, $this->maxRetries);
        $this->prefetch = max(1, $this->prefetch);
        $this->heartbeatSec = max(0, $this->heartbeatSec);
    }
}

<?php

declare(strict_types=1);

use SpsFW\Core\Queue\WorkerExecutionPolicy;

require_once dirname(__DIR__) . '/bootstrap.php';

$policy = new WorkerExecutionPolicy(retryDelayMs: 25);
assert_same(25, $policy->retryDelayMs, 'worker retry delay is owned by the broker worker policy');
echo "Worker retry policy contract passed\n";

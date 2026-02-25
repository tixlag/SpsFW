<?php

namespace SpsFW\Core\Queue;

/**
 * @deprecated Use PayloadQueueJob instead.
 *
 * Kept for backward compatibility with legacy code that extends QueueJob.
 */
abstract class QueueJob extends PayloadQueueJob
{
}

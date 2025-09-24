<?php
namespace SpsFW\Core\Queue;

enum JobResult: string
{
    case Success = 'success';
    case Retry   = 'retry';
    case Failed  = 'failed';
}

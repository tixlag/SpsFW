<?php

namespace SpsNext\Workers\Errors;

use Psr\Log\LoggerInterface;
use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Workers\WorkerConfig;
use Throwable;

class GlobalErrorHandler
{
    private QueueClientAndPublisherFactory $queueFactory;
    private WorkerConfig $workerConfig;
    private LoggerInterface $logger;

    public function __construct(
        QueueClientAndPublisherFactory $queueFactory,
        WorkerConfig                   $workerConfig,
        LoggerInterface                $logger
    )
    {
        $this->queueFactory = $queueFactory;
        $this->workerConfig = $workerConfig;
        $this->logger = $logger;
    }

    public function handleException(Throwable $exception): void
    {
        try {

            $job = new ErrorJob(
                user: Auth::getOrNull(),
                message: $exception->getMessage(),
                file: $exception->getFile(),
                line: $exception->getLine(),
                trace: $exception->getTraceAsString(),
                type: get_class($exception),
                code: $exception->getCode(),
                timestamp: date('Y-m-d H:i:s'),
                requestUri: $_SERVER['REQUEST_URI'] ?? null
            );

//            $this->logger->warning("PHP Error (exception)", [
//                'error' => $job
//            ]);
//            return;


            $publisher = $this->queueFactory->createByWorkerName('errors-worker');

            $publisher->publish($job);

//            $this->logger->info('Exception sent to error queue', [
//                'message' => $exception->getMessage(),
//                'file' => $exception->getFile(),
//                'line' => $exception->getLine()
//            ]);
        } catch (Throwable $e) {
            // Log the failure to send to queue
            $this->logger->error('Failed to send exception to error queue', [
                'error' => $e->getMessage(),
                'original_exception' => $exception->getMessage()
            ]);
        }
    }

    public function handleError(
        int    $errno,
        string $errstr,
        string $errfile,
        int    $errline
    ): bool
    {
        try {
            $errorTypes = [
                E_ERROR => 'E_ERROR',
                E_WARNING => 'E_WARNING',
                E_PARSE => 'E_PARSE',
                E_NOTICE => 'E_NOTICE',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_CORE_WARNING => 'E_CORE_WARNING',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_COMPILE_WARNING => 'E_COMPILE_WARNING',

                E_USER_ERROR => 'E_USER_ERROR',
                E_USER_WARNING => 'E_USER_WARNING',
                E_USER_NOTICE => 'E_USER_NOTICE',
                E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                E_DEPRECATED => 'E_DEPRECATED',
                E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            ];

            $errorType = $errorTypes[$errno] ?? 'UNKNOWN_ERROR';

            // For less severe errors, we might not want to queue them
            $severeErrors = [
                'E_ERROR',
                'E_CORE_ERROR',
                'E_COMPILE_ERROR',
                'E_USER_ERROR',
                'E_RECOVERABLE_ERROR'
            ];

            $excludeErrors = [
                'E_DEPRECATED',
                'E_USER_DEPRECATED',
                'E_WARNING'
            ];

            // Log but don't queue less severe errors

            if (in_array($errorType, $excludeErrors) and !str_contains($errstr, 'Permission denied')) {
//                $this->logger->warning("PHP Error (not queued)", [
//                    'type' => $errorType,
//                    'message' => $errstr,
//                    'file' => $errfile,
//                    'line' => $errline
//                ]);
                return false;
            }
            $user = Auth::getOrNull();
            $job = new ErrorJob(
                user: $user,
                message: $errstr,
                file: $errfile,
                line: $errline,
                trace: "Error occurred in PHP internals, no trace available",
                type: $errorType,
                code: $errno,
                timestamp: date('Y-m-d H:i:s'),
                requestUri: $_SERVER['REQUEST_URI'] ?? null
            );

            $publisher = $this->queueFactory->createByWorkerName(
                'errors-worker'
            );

            $publisher->publish($job);

//            $this->logger->info('Error sent to error queue', [
//                'type' => $errorType,
//                'message' => $errstr,
//                'file' => $errfile,
//                'line' => $errline
//            ]);

            return true;
        } catch (Throwable $e) {
            // Log the failure to send to queue
            $this->logger->error('Failed to send error to error queue', [
                'error' => $e->getMessage(),
                'original_error' => $errstr
            ]);
            return false;
        }
    }
}

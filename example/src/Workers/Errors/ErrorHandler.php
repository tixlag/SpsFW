<?php

namespace SpsNext\Workers\Errors;

use Psr\Log\LoggerInterface;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Queue\Attributes\JobHandler;
use SpsFW\Core\Queue\Interfaces\JobHandlerInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\JobResult;
use SpsNext\Psr\FlexibleTelegramNotifier;

#[JobHandler('errors-worker')]
class ErrorHandler implements JobHandlerInterface
{
    public function __construct(
        #[Inject]
        private FlexibleTelegramNotifier $telegramNotifier,
        #[Inject]
        private LoggerInterface $logger
    ) {
    }

    public function handle(JobInterface $job): JobResult
    {
        if (!$job instanceof ErrorJob) {
            $this->logger->error('Invalid job type passed to ErrorHandler', [
                'actual_type' => get_class($job),
            ]);
            return JobResult::Failed;
        }

        try {
            $message = $this->buildJsonMessage($job);
            $this->telegramNotifier->send($message);

            return JobResult::Success;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send error to Telegram', [
                'error' => $e->getMessage(),
                'original_error_message' => $job->message,
                'original_error_file' => $job->file
            ]);
            
            // Even if Telegram fails, we shouldn't retry since it's already an error handler
            return JobResult::Failed;
        }
    }

    private function buildJsonMessage(ErrorJob $job): string
    {
        $header = "🚨 *Error Report*\n";
        
        // Timestamp
        $header .= "⏰ `{$job->timestamp}`\n";
        
        // User info with clickable link
        if ($job->user !== null && $job->user->uuid) {
            $header .= "👤 Пользователь `{$job->user->uuid}`\n";
        }
        
        if ($job->requestUri !== null) {
            $link = 'https://lk.sps38.pro' . $job->requestUri;
            $header .= "🌐 URI: [{$job->requestUri}]({$link})\n";
        }
        $header .= "\n";

        $errorData = [
            'type' => $job->type,
            'code' => $job->code,
            'message' => $job->message,
            'file' => $job->file,
            'line' => $job->line,
            'additionalContext' => $job->additionalContext,
            'trace' => $job->trace,
        ];

        return $header . "```json\n" . json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "```";
    }
}

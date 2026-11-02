<?php

namespace MainaSuperintelligence\Sms\Jobs;

use MainaSuperintelligence\Sms\Enums\SmsStatusEnum;
use MainaSuperintelligence\Sms\Models\SmsLog;
use MainaSuperintelligence\Sms\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one sms_logs row through Africa's Talking.
 *
 * Transient failures (network, HTTP 5xx, gateway errors) are retried with backoff on a real
 * queue. On the sync driver there is no queue to come back to, so the log is marked failed at
 * once and can be resent from the admin.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 60;

    /**
     * Seconds to wait before attempt 2, 3 and 4.
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $logId)
    {
    }

    public function handle(SmsService $sms): void
    {
        $log = SmsLog::query()->find($this->logId);

        if (! $log || ! $log->isStatus(SmsStatusEnum::QUEUED)) {
            return;
        }

        if ($log->send_after && $log->send_after->isFuture()) {
            // Quiet hours: sms:send-scheduled dispatches it again when the window ends.
            return;
        }

        if ($sms->attempt($log) !== SmsService::ATTEMPT_RETRY) {
            return;
        }

        if ($this->canRetry()) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $sms->markFailed($log->refresh(), $log->error ?: 'Sending failed after retries.');
    }

    public function failed(?Throwable $exception = null): void
    {
        $log = SmsLog::query()->find($this->logId);

        if ($log && $log->isStatus(SmsStatusEnum::QUEUED)) {
            app(SmsService::class)->markFailed($log, $exception?->getMessage() ?: 'Sending failed.');
        }
    }

    protected function canRetry(): bool
    {
        return $this->job
            && $this->job->getConnectionName() !== 'sync'
            && $this->attempts() < $this->tries;
    }
}

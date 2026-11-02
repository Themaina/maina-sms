<?php

namespace Maina888\Sms\Services;

use Maina888\Sms\Enums\SmsStatusEnum;
use Maina888\Sms\Events\SmsDelivered;
use Maina888\Sms\Events\SmsFailed;
use Maina888\Sms\Events\SmsSent;
use Maina888\Sms\Jobs\SendSmsJob;
use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Supports\PhoneNumber;
use Maina888\Sms\Supports\SmsSettings;
use Maina888\Sms\Supports\SmsText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Public SMS API for every plugin.
 *
 *   app(SmsService::class)->send('+2547XXXXXXXX', 'Hello');                    // SmsLog
 *   app(SmsService::class)->send(['0712...', '0722...'], 'Hello');              // Collection<SmsLog>
 *   app(SmsService::class)->sendTemplate($phone, 'order_placed', [...]);       // ?SmsLog
 *
 * Context keys with special meaning: "related" (an Eloquent model, stored as the related morph)
 * and "template_key". Everything else is stored as JSON on the log.
 */
class SmsService
{
    public const ATTEMPT_DONE = 'done';

    public const ATTEMPT_RETRY = 'retry';

    public const BALANCE_CACHE_KEY = 'sms_africastalking_balance';

    public function client(): AfricasTalkingClient
    {
        return app(AfricasTalkingClient::class);
    }

    public function enabled(): bool
    {
        return SmsSettings::enabled();
    }

    public function normalisePhone(string $phone): ?string
    {
        return PhoneNumber::normalise($phone, SmsSettings::defaultCountryCode());
    }

    /**
     * Queue an SMS to one or many recipients.
     *
     * Non-urgent messages created during quiet hours wait until the window ends (they stay
     * "queued" with send_after set, and sms:send-scheduled picks them up).
     *
     * @return SmsLog|Collection<int, SmsLog> a single log for a string recipient, a collection for an array
     */
    public function send(string|array $to, string $message, array $context = [], bool $urgent = false): SmsLog|Collection
    {
        $related = Arr::pull($context, 'related');
        $templateKey = Arr::pull($context, 'template_key');

        $message = trim($message);
        $releaseAt = $urgent ? null : SmsSettings::quietHours()->releaseAt();
        $enabled = $this->enabled();

        $recipients = collect(is_array($to) ? $to : [$to])
            ->map(fn ($phone) => trim((string) $phone))
            ->filter()
            ->unique(fn (string $phone) => $this->normalisePhone($phone) ?? $phone)
            ->values();

        $logs = collect();

        foreach ($recipients as $raw) {
            $number = $this->normalisePhone($raw);

            $log = new SmsLog([
                'to' => $number ?: Str::limit($raw, 28, ''),
                'message' => $message,
                'template_key' => $templateKey,
                'status' => SmsStatusEnum::QUEUED,
                'provider' => 'africastalking',
                'segments' => SmsText::segments($message),
                'context' => $context ?: null,
                'urgent' => $urgent,
                'send_after' => $releaseAt ? Carbon::instance($releaseAt)->setTimezone(config('app.timezone')) : null,
            ]);

            if ($related instanceof Model && $related->exists) {
                $log->related()->associate($related);
            }

            $log->save();

            $logs->push($log);

            if (! $number) {
                $this->markFailed($log, 'Invalid phone number.', SmsStatusEnum::REJECTED);

                continue;
            }

            if (! $enabled) {
                $this->markFailed($log, 'SMS sending is disabled in Settings > SMS.');

                continue;
            }

            if ($message === '') {
                $this->markFailed($log, 'Empty message.');

                continue;
            }

            if (! $log->send_after) {
                $this->dispatch($log);
            }
        }

        return is_array($to) ? $logs : ($logs->first() ?? $this->emptyRecipientLog($message, $templateKey));
    }

    /**
     * Render a registered template and send it. Returns null when SMS is disabled, the
     * template is unknown, or its toggle is off in Settings > SMS.
     *
     * $urgent = null uses the template's own default (order notifications are urgent,
     * reminders are not).
     */
    public function sendTemplate(string|array $to, string $key, array $vars = [], array $context = [], ?bool $urgent = null): SmsLog|Collection|null
    {
        $template = $this->template($key);

        if (! $template || ! $this->enabled() || ! $this->templateEnabled($key)) {
            return null;
        }

        $context['template_key'] = $key;

        return $this->send($to, $this->renderTemplate($key, $vars), $context, $urgent ?? (bool) $template['urgent']);
    }

    /**
     * All templates registered through the "sms_templates" filter, keyed by template key.
     *
     * Each item: key, label, default, variables (name => description), group, urgent, enabled.
     */
    public function templates(): array
    {
        $registered = apply_filters('sms_templates', []);

        $templates = [];

        foreach ((array) $registered as $index => $template) {
            if (! is_array($template)) {
                continue;
            }

            $key = (string) ($template['key'] ?? (is_string($index) ? $index : ''));

            if ($key === '' || ! preg_match('/^[a-z0-9_]+$/', $key)) {
                continue;
            }

            $variables = (array) ($template['variables'] ?? []);

            if (array_is_list($variables)) {
                $variables = array_combine($variables, array_map(fn ($name) => Str::headline($name), $variables));
            }

            $templates[$key] = [
                'key' => $key,
                'label' => (string) ($template['label'] ?? Str::headline($key)),
                'default' => (string) ($template['default'] ?? $template['default_text'] ?? ''),
                'variables' => $variables,
                'group' => (string) ($template['group'] ?? 'General'),
                'urgent' => (bool) ($template['urgent'] ?? false),
                'enabled' => (bool) ($template['enabled'] ?? true),
            ];
        }

        return $templates;
    }

    public function template(string $key): ?array
    {
        return $this->templates()[$key] ?? null;
    }

    public function templateEnabled(string $key): bool
    {
        $template = $this->template($key);

        return $template && SmsSettings::templateEnabled($key, $template['enabled']);
    }

    public function templateText(string $key): string
    {
        return SmsSettings::templateText($key) ?? ($this->template($key)['default'] ?? '');
    }

    /**
     * Render template $key (admin override or the registered default) with {{var}} placeholders.
     */
    public function renderTemplate(string $key, array $vars): string
    {
        return SmsText::render($this->templateText($key), array_merge($this->globalVariables(), $vars));
    }

    public function globalVariables(): array
    {
        return [
            'site_name' => (string) (setting('site_title') ?: config('app.name')),
            'site_url' => url('/'),
        ];
    }

    /**
     * Africa's Talking balance, e.g. "KES 1234.5600". Cached for five minutes.
     */
    public function balance(bool $fresh = false): ?string
    {
        if ($fresh) {
            Cache::forget(self::BALANCE_CACHE_KEY);
        }

        $cached = Cache::get(self::BALANCE_CACHE_KEY);

        if ($cached) {
            return $cached;
        }

        $balance = $this->client()->balance();

        if ($balance) {
            Cache::put(self::BALANCE_CACHE_KEY, $balance, now()->addMinutes(5));
        }

        return $balance;
    }

    public function dispatch(SmsLog $log): void
    {
        SendSmsJob::dispatch($log->getKey())->afterCommit();
    }

    /**
     * One delivery attempt. Returns ATTEMPT_RETRY when the failure is transient.
     */
    public function attempt(SmsLog $log): string
    {
        $log->forceFill(['attempts' => $log->attempts + 1])->save();

        try {
            $result = $this->client()->send($log->to, $log->message);
        } catch (AfricasTalkingException $exception) {
            $log->forceFill(['error' => Str::limit($exception->getMessage(), 1000)])->save();

            return self::ATTEMPT_RETRY;
        }

        if ($result['accepted']) {
            $log->forceFill([
                'status' => SmsStatusEnum::SENT,
                'provider_message_id' => $result['message_id'],
                'cost' => $result['cost'],
                'currency' => $result['currency'],
                'error' => null,
                'sent_at' => now(),
            ])->save();

            event(new SmsSent($log));

            return self::ATTEMPT_DONE;
        }

        if ($result['transient']) {
            $log->forceFill(['error' => $result['error']])->save();

            return self::ATTEMPT_RETRY;
        }

        $this->markFailed(
            $log,
            $result['error'] ?: 'Rejected by Africa\'s Talking.',
            $result['rejected'] ? SmsStatusEnum::REJECTED : SmsStatusEnum::FAILED,
            $result['message_id'],
        );

        return self::ATTEMPT_DONE;
    }

    public function markFailed(SmsLog $log, string $reason, string $status = SmsStatusEnum::FAILED, ?string $providerMessageId = null): SmsLog
    {
        $log->forceFill(array_filter([
            'status' => $status,
            'error' => Str::limit($reason, 1000),
            'provider_message_id' => $providerMessageId ?: $log->provider_message_id,
        ], fn ($value) => $value !== null))->save();

        event(new SmsFailed($log, $reason));

        return $log;
    }

    /**
     * Queue a copy of $log (same number, text, template and related record), urgently.
     */
    public function resend(SmsLog $log): SmsLog
    {
        $context = array_merge((array) $log->context, [
            'resent_from' => $log->getKey(),
            'template_key' => $log->template_key,
        ]);

        if ($log->related_type && $log->related_id && class_exists($log->related_type)) {
            $context['related'] = $log->related;
        }

        return $this->send($log->to, $log->message, $context, true);
    }

    /**
     * Apply an Africa's Talking delivery report (form fields id, status, phoneNumber,
     * failureReason). Returns the updated log, or null when the id is unknown.
     */
    public function handleDeliveryReport(array $payload): ?SmsLog
    {
        $messageId = trim((string) Arr::get($payload, 'id', ''));

        if ($messageId === '') {
            return null;
        }

        $log = SmsLog::query()->where('provider_message_id', $messageId)->latest('id')->first();

        if (! $log) {
            return null;
        }

        $status = (string) Arr::get($payload, 'status', '');
        $failureReason = trim((string) Arr::get($payload, 'failureReason', '')) ?: null;

        switch ($status) {
            case 'Success':
                if (! $log->isStatus(SmsStatusEnum::DELIVERED)) {
                    $log->forceFill([
                        'status' => SmsStatusEnum::DELIVERED,
                        'delivered_at' => now(),
                        'error' => null,
                    ])->save();

                    event(new SmsDelivered($log));
                }

                break;

            case 'Failed':
            case 'Rejected':
                if (! $log->isStatus(SmsStatusEnum::FAILED, SmsStatusEnum::REJECTED)) {
                    $this->markFailed(
                        $log,
                        $failureReason ?: 'Delivery ' . strtolower($status),
                        $status === 'Rejected' ? SmsStatusEnum::REJECTED : SmsStatusEnum::FAILED,
                    );
                }

                break;

            case 'Buffered':
            case 'Sent':
            case 'Submitted':
                if ($log->isStatus(SmsStatusEnum::QUEUED)) {
                    $log->forceFill(['status' => SmsStatusEnum::SENT, 'sent_at' => $log->sent_at ?: now()])->save();
                }

                break;
        }

        $log->forceFill([
            'context' => array_merge((array) $log->context, [
                'delivery' => array_filter([
                    'status' => $status ?: null,
                    'failure_reason' => $failureReason,
                    'network_code' => Arr::get($payload, 'networkCode'),
                    'received_at' => now()->toIso8601String(),
                ]),
            ]),
        ])->save();

        return $log;
    }

    /**
     * Dispatch queued messages whose quiet-hours wait is over. Returns how many were dispatched.
     */
    public function dispatchDue(): int
    {
        $count = 0;

        SmsLog::query()
            ->where('status', SmsStatusEnum::QUEUED)
            ->whereNotNull('send_after')
            ->where('send_after', '<=', now())
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                // Claim the row so two overlapping runs never send it twice.
                $claimed = SmsLog::query()
                    ->whereKey($id)
                    ->where('status', SmsStatusEnum::QUEUED)
                    ->whereNotNull('send_after')
                    ->update(['send_after' => null]);

                if ($claimed) {
                    SendSmsJob::dispatch($id);
                    $count++;
                }
            });

        return $count;
    }

    protected function emptyRecipientLog(string $message, ?string $templateKey): SmsLog
    {
        $log = SmsLog::query()->create([
            'to' => '',
            'message' => $message,
            'template_key' => $templateKey,
            'status' => SmsStatusEnum::QUEUED,
            'segments' => SmsText::segments($message),
        ]);

        return $this->markFailed($log, 'No recipient.', SmsStatusEnum::REJECTED);
    }
}

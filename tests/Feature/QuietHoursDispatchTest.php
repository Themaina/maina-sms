<?php

namespace Maina888\Sms\Tests\Feature;

use Maina888\Sms\Jobs\SendSmsJob;
use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Tests\SmsTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Quiet hours end to end: non-urgent messages wait (no request), urgent ones go at once, and
 * held messages are released exactly once when the window ends.
 */
class QuietHoursDispatchTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAccepted();
    }

    protected function sms(): SmsService
    {
        return app(SmsService::class);
    }

    protected function requestsSent(): int
    {
        return count(Http::recorded());
    }

    public function test_non_urgent_message_at_night_is_held_until_seven(): void
    {
        $this->travelToNairobi('2026-10-20 23:45');

        $log = $this->sms()->send('0712345678', 'Reminder ' . self::FIXTURE_PREFIX)->refresh();

        $this->assertSame('queued', $log->statusValue());
        $this->assertTrue($log->send_after->equalTo(Carbon::parse('2026-10-21 07:00', 'Africa/Nairobi')));
        $this->assertSame(0, $this->requestsSent());
    }

    public function test_early_morning_message_waits_for_the_same_morning(): void
    {
        $this->travelToNairobi('2026-10-21 05:30');

        $log = $this->sms()->send('0712345678', 'Reminder ' . self::FIXTURE_PREFIX)->refresh();

        $this->assertTrue($log->send_after->equalTo(Carbon::parse('2026-10-21 07:00', 'Africa/Nairobi')));
    }

    public function test_window_boundaries(): void
    {
        $this->travelToNairobi('2026-10-20 20:59');
        $this->assertNull($this->sms()->send('0712345678', 'Before ' . self::FIXTURE_PREFIX)->refresh()->send_after);

        $this->travelToNairobi('2026-10-20 21:00');
        $this->assertNotNull($this->sms()->send('0712345678', 'Start ' . self::FIXTURE_PREFIX)->refresh()->send_after);

        $this->travelToNairobi('2026-10-21 06:59');
        $this->assertNotNull($this->sms()->send('0712345678', 'Late ' . self::FIXTURE_PREFIX)->refresh()->send_after);

        $this->travelToNairobi('2026-10-21 07:00');
        $this->assertNull($this->sms()->send('0712345678', 'End ' . self::FIXTURE_PREFIX)->refresh()->send_after);
    }

    public function test_urgent_message_ignores_quiet_hours(): void
    {
        $this->travelToNairobi('2026-10-20 23:45');

        $log = $this->sms()->send('0712345678', 'Your code ' . self::FIXTURE_PREFIX, [], true)->refresh();

        $this->assertSame('sent', $log->statusValue());
        $this->assertNull($log->send_after);
        $this->assertSame(1, $this->requestsSent());
    }

    public function test_disabled_quiet_hours_send_at_once(): void
    {
        setting()->set('sms_quiet_hours_enabled', '0');
        $this->travelToNairobi('2026-10-20 23:45');

        $this->assertSame('sent', $this->sms()->send('0712345678', 'Now ' . self::FIXTURE_PREFIX)->refresh()->statusValue());
    }

    public function test_job_never_sends_a_held_message_early(): void
    {
        $this->travelToNairobi('2026-10-20 23:45');

        $log = $this->sms()->send('0712345678', 'Held ' . self::FIXTURE_PREFIX)->refresh();

        // E.g. a resend or a stray dispatch while the window is still open.
        SendSmsJob::dispatchSync($log->getKey());

        $this->assertSame('queued', $log->refresh()->statusValue());
        $this->assertSame(0, $this->requestsSent());
    }

    public function test_held_message_is_released_once_when_the_window_ends(): void
    {
        $this->travelToNairobi('2026-10-20 23:45');
        $log = $this->sms()->send('0712345678', 'Held ' . self::FIXTURE_PREFIX)->refresh();

        // Still inside the window: nothing due yet.
        $this->travelToNairobi('2026-10-21 06:30');
        $this->sms()->dispatchDue();
        $this->assertSame('queued', $log->refresh()->statusValue());

        $this->travelToNairobi('2026-10-21 07:01');
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('sms:send-scheduled'));

        $log->refresh();
        $this->assertSame('sent', $log->statusValue());
        $this->assertNull($log->send_after);
        $this->assertSame(1, $this->sentTo($log));

        // A second (overlapping) run does not send it again.
        $this->sms()->dispatchDue();
        $this->assertSame(1, $this->sentTo($log));
    }

    public function test_send_template_uses_the_template_urgency(): void
    {
        add_filter('sms_templates', function (array $templates): array {
            $templates['it_plugintests_receipt'] = ['label' => 'Receipt', 'default' => 'Receipt {{ref}}', 'urgent' => true];
            $templates['it_plugintests_reminder'] = ['label' => 'Reminder', 'default' => 'Reminder {{ref}}'];

            return $templates;
        }, 999);

        $this->travelToNairobi('2026-10-20 23:45');

        $receipt = $this->sms()->sendTemplate('0712345678', 'it_plugintests_receipt', ['ref' => 'R1']);
        $reminder = $this->sms()->sendTemplate('0712345678', 'it_plugintests_reminder', ['ref' => 'R2']);
        $forced = $this->sms()->sendTemplate('0712345678', 'it_plugintests_reminder', ['ref' => 'R3'], [], true);

        $this->assertSame('sent', $receipt->refresh()->statusValue());
        $this->assertSame('Receipt R1', $receipt->message);
        $this->assertSame('it_plugintests_receipt', $receipt->template_key);
        $this->assertSame('queued', $reminder->refresh()->statusValue());
        $this->assertNotNull($reminder->send_after);
        $this->assertSame('sent', $forced->refresh()->statusValue());
    }

    protected function sentTo(SmsLog $log): int
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]['message'] === $log->message)
            ->count();
    }
}

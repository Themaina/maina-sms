<?php

namespace Maina888\Sms\Tests\Feature;

use Maina888\Sms\Events\SmsDelivered;
use Maina888\Sms\Events\SmsFailed;
use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsSettings;
use Maina888\Sms\Tests\SmsTestCase;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Africa's Talking delivery reports: the URL token is an HMAC of APP_KEY, anything else is 403
 * and changes nothing; valid reports update the matching log once.
 */
class DeliveryReportTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAccepted();
    }

    protected function sentLog(): SmsLog
    {
        $log = app(SmsService::class)->send('0712345678', 'Delivery report test ' . self::FIXTURE_PREFIX, [], true)->refresh();

        $this->assertSame('sent', $log->statusValue());
        $this->assertStringStartsWith('ATXid_' . self::FIXTURE_PREFIX, (string) $log->provider_message_id);

        return $log;
    }

    protected function report(string $token, array $fields)
    {
        return $this->post('/sms/africastalking/delivery/' . $token, $fields);
    }

    public function test_token_is_a_40_char_hmac_of_the_app_key(): void
    {
        $token = SmsSettings::deliveryToken();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $token);
        $this->assertSame(substr(hash_hmac('sha256', 'sms.africastalking.delivery-report', (string) config('app.key')), 0, 40), $token);
        $this->assertStringEndsWith('/sms/africastalking/delivery/' . $token, SmsSettings::deliveryReportUrl());

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->assertNotSame($token, SmsSettings::deliveryToken());
    }

    public function test_forged_tokens_are_rejected_and_change_nothing(): void
    {
        Event::fake([SmsDelivered::class, SmsFailed::class]);

        $log = $this->sentLog();
        $valid = SmsSettings::deliveryToken();

        $forged = [
            str_repeat('a', 40),
            substr($valid, 0, 39),            // truncated
            $valid . 'a',                      // extended
            strtoupper($valid),                // case matters
            hash_hmac('sha256', 'sms.africastalking.delivery-report', 'some-other-key'),
        ];

        foreach ($forged as $token) {
            $this->report($token, ['id' => $log->provider_message_id, 'status' => 'Failed', 'failureReason' => 'UserInBlacklist'])
                ->assertForbidden();
        }

        $log->refresh();

        $this->assertSame('sent', $log->statusValue());
        $this->assertNull($log->delivered_at);
        $this->assertNull(data_get($log->context, 'delivery'));
        Event::assertNotDispatched(SmsDelivered::class);
        Event::assertNotDispatched(SmsFailed::class);
    }

    public function test_token_with_symbols_does_not_match_the_route(): void
    {
        $this->report('../../admin', ['id' => 'x', 'status' => 'Success'])->assertNotFound();
        $this->report(SmsSettings::deliveryToken() . '%2F', ['id' => 'x', 'status' => 'Success'])->assertNotFound();
    }

    public function test_success_report_marks_delivered_once(): void
    {
        Event::fake([SmsDelivered::class, SmsFailed::class]);

        $log = $this->sentLog();
        $token = SmsSettings::deliveryToken();

        $this->report($token, ['id' => $log->provider_message_id, 'status' => 'Success', 'networkCode' => '63902'])
            ->assertOk()
            ->assertJson(['ok' => true, 'matched' => true, 'status' => 'delivered']);

        // Africa's Talking retries: a repeated report does not fire the event again.
        $this->report($token, ['id' => $log->provider_message_id, 'status' => 'Success'])->assertOk();

        $log->refresh();

        $this->assertSame('delivered', $log->statusValue());
        $this->assertNotNull($log->delivered_at);
        $this->assertSame('Success', data_get($log->context, 'delivery.status'));
        Event::assertDispatchedTimes(SmsDelivered::class, 1);
    }

    public function test_failed_and_rejected_reports(): void
    {
        Event::fake([SmsDelivered::class, SmsFailed::class]);

        $failed = $this->sentLog();
        $rejected = $this->sentLog();
        $token = SmsSettings::deliveryToken();

        $this->report($token, ['id' => $failed->provider_message_id, 'status' => 'Failed', 'failureReason' => 'AbsentSubscriber'])->assertOk();
        $this->report($token, ['id' => $failed->provider_message_id, 'status' => 'Failed', 'failureReason' => 'AbsentSubscriber'])->assertOk();
        $this->report($token, ['id' => $rejected->provider_message_id, 'status' => 'Rejected', 'failureReason' => 'InvalidPhoneNumber'])->assertOk();

        $this->assertSame('failed', $failed->refresh()->statusValue());
        $this->assertSame('AbsentSubscriber', $failed->error);
        $this->assertSame('rejected', $rejected->refresh()->statusValue());
        Event::assertDispatchedTimes(SmsFailed::class, 2);
    }

    public function test_unknown_or_missing_id_is_acknowledged_without_changes(): void
    {
        $token = SmsSettings::deliveryToken();

        $this->report($token, ['id' => 'ATXid_' . self::FIXTURE_PREFIX . '_unknown', 'status' => 'Success'])
            ->assertOk()
            ->assertJson(['ok' => true, 'matched' => false, 'status' => null]);

        $this->report($token, ['status' => 'Success'])->assertOk()->assertJson(['matched' => false]);
    }

    public function test_report_only_touches_the_matching_log(): void
    {
        $first = $this->sentLog();
        $second = $this->sentLog();

        $this->report(SmsSettings::deliveryToken(), ['id' => $first->provider_message_id, 'status' => 'Success'])->assertOk();

        $this->assertSame('delivered', $first->refresh()->statusValue());
        $this->assertSame('sent', $second->refresh()->statusValue());
    }
}

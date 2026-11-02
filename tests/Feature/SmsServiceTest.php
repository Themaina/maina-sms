<?php

namespace MainaSuperintelligence\Sms\Tests\Feature;

use Botble\Ecommerce\Events\OrderPlacedEvent;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderAddress;
use MainaSuperintelligence\Sms\Events\SmsFailed;
use MainaSuperintelligence\Sms\Events\SmsSent;
use MainaSuperintelligence\Sms\Listeners\SendOrderSmsListener;
use MainaSuperintelligence\Sms\Models\SmsLog;
use MainaSuperintelligence\Sms\Services\SmsService;
use MainaSuperintelligence\Sms\Supports\SmsSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Needs the sms plugin active (php artisan cms:plugin:activate sms) and phpunit installed
 * (composer install with dev dependencies). Run: vendor/bin/phpunit platform/plugins/sms/tests
 */
class SmsServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected SmsService $sms;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_plugin_active('sms')) {
            $this->markTestSkipped('sms plugin is not active.');
        }

        setting()->set([
            'sms_enabled' => '1',
            'sms_environment' => 'sandbox',
            'sms_api_key' => SmsSettings::encryptApiKey('atsk_test_key'),
            'sms_quiet_hours_enabled' => '1',
            'sms_quiet_hours_start' => '21:00',
            'sms_quiet_hours_end' => '07:00',
            'sms_timezone' => 'Africa/Nairobi',
            'sms_default_country_code' => '254',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'Africa/Nairobi')->utc());

        $this->sms = app(SmsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function fakeAccepted(string $messageId = 'ATXid_1'): void
    {
        Http::fake(['api.sandbox.africastalking.com/version1/messaging' => Http::response([
            'SMSMessageData' => [
                'Message' => 'Sent to 1/1 Total Cost: KES 0.8000',
                'Recipients' => [[
                    'statusCode' => 101,
                    'number' => '+254712345678',
                    'status' => 'Success',
                    'cost' => 'KES 0.8000',
                    'messageId' => $messageId,
                ]],
            ],
        ], 201)]);
    }

    public function test_send_moves_queued_to_sent_with_id_and_cost(): void
    {
        Event::fake([SmsSent::class, SmsFailed::class]);
        $this->fakeAccepted();

        $log = $this->sms->send('0712 345 678', 'Hello')->refresh();

        $this->assertSame('sent', $log->statusValue());
        $this->assertSame('ATXid_1', $log->provider_message_id);
        $this->assertEqualsWithDelta(0.8, $log->cost, 0.0001);
        $this->assertSame('KES', $log->currency);
        $this->assertSame('+254712345678', $log->to);
        Event::assertDispatched(SmsSent::class);

        Http::assertSent(fn (ClientRequest $request) => $request['username'] === 'sandbox'
            && $request['to'] === '+254712345678'
            && $request->hasHeader('apiKey', 'atsk_test_key'));
    }

    public function test_valid_delivery_report_marks_delivered_and_forged_token_is_403(): void
    {
        $this->fakeAccepted('ATXid_dlr');
        $log = $this->sms->send('0712345678', 'Hello')->refresh();

        $this->post('/sms/africastalking/delivery/' . str_repeat('a', 40), ['id' => 'ATXid_dlr', 'status' => 'Failed'])
            ->assertForbidden();
        $this->assertSame('sent', $log->refresh()->statusValue());

        $this->post('/sms/africastalking/delivery/' . SmsSettings::deliveryToken(), ['id' => 'ATXid_dlr', 'status' => 'Success'])
            ->assertOk();
        $this->assertSame('delivered', $log->refresh()->statusValue());
        $this->assertNotNull($log->delivered_at);
    }

    public function test_failure_marks_failed_and_fires_sms_failed(): void
    {
        Event::fake([SmsSent::class, SmsFailed::class]);
        Http::fake(['api.sandbox.africastalking.com/*' => Http::response('Service Unavailable', 503)]);

        $log = $this->sms->send('0712345678', 'Will fail', [], true)->refresh();

        $this->assertSame('failed', $log->statusValue());
        Event::assertDispatched(SmsFailed::class, fn (SmsFailed $event) => $event->log->is($log));
    }

    public function test_quiet_hours_hold_non_urgent_messages(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-20 22:30', 'Africa/Nairobi')->utc());

        $log = $this->sms->send('0712345678', 'Reminder')->refresh();

        $this->assertSame('queued', $log->statusValue());
        $this->assertTrue($log->send_after->equalTo(Carbon::parse('2026-10-21 07:00', 'Africa/Nairobi')));
        Http::assertNothingSent();
    }

    public function test_order_placed_event_queues_order_placed_template(): void
    {
        $this->fakeAccepted('ATXid_order');

        Event::forget(OrderPlacedEvent::class);
        Event::listen(OrderPlacedEvent::class, [SendOrderSmsListener::class, 'handle']);

        $order = Order::query()->forceCreate(['amount' => 4500, 'sub_total' => 4500, 'user_id' => 0, 'status' => 'pending', 'is_finished' => 1]);
        OrderAddress::query()->forceCreate(['order_id' => $order->getKey(), 'name' => 'Demo Customer', 'phone' => '0712345678']);

        event(new OrderPlacedEvent($order->refresh()));

        $log = SmsLog::query()->where('related_type', $order->getMorphClass())->where('related_id', $order->getKey())->first();

        $this->assertNotNull($log);
        $this->assertSame('order_placed', $log->template_key);
        $this->assertStringContainsString('Hi Demo,', $log->message);
        $this->assertStringContainsString((string) $order->code, $log->message);
    }
}

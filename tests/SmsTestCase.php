<?php

namespace Maina888\Sms\Tests;

use Maina888\Sms\Supports\SmsSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Boots the app against the .env database. Every test runs inside a transaction that is rolled
 * back, settings change in memory only, and Africa's Talking is never contacted.
 */
abstract class SmsTestCase extends TestCase
{
    use DatabaseTransactions;

    public const FIXTURE_PREFIX = 'it-plugintests';

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_plugin_active('sms')) {
            $this->markTestSkipped('The sms plugin is not active.');
        }

        Http::preventStrayRequests();

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

        $this->travelToNairobi('2026-10-20 12:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function travelToNairobi(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Africa/Nairobi')->utc());
    }

    /**
     * Fake Africa's Talking accepting every message, each with a new message id.
     */
    protected function fakeAccepted(): void
    {
        $counter = 0;

        Http::fake(['api.sandbox.africastalking.com/version1/messaging' => function () use (&$counter) {
            $counter++;

            return Http::response([
                'SMSMessageData' => [
                    'Message' => 'Sent to 1/1 Total Cost: KES 0.8000',
                    'Recipients' => [[
                        'statusCode' => 101,
                        'number' => '+254712345678',
                        'status' => 'Success',
                        'cost' => 'KES 0.8000',
                        'messageId' => 'ATXid_' . self::FIXTURE_PREFIX . '_' . $counter . '_' . uniqid(),
                    ]],
                ],
            ], 201);
        }]);
    }
}

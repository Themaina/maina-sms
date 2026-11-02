<?php

namespace MainaSuperintelligence\Sms\Tests\Unit;

use MainaSuperintelligence\Sms\Supports\QuietHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Supports/QuietHours.php';

class QuietHoursTest extends TestCase
{
    protected function nairobi(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Africa/Nairobi');
    }

    public function test_overnight_window_evening_waits_until_next_morning(): void
    {
        $quiet = new QuietHours('21:00', '07:00', 'Africa/Nairobi');

        $release = $quiet->releaseAt($this->nairobi('2026-10-20 22:30'));

        $this->assertNotNull($release);
        $this->assertSame('2026-10-21 07:00', $release->format('Y-m-d H:i'));
        $this->assertSame('Africa/Nairobi', $release->getTimezone()->getName());
    }

    public function test_overnight_window_early_morning_waits_until_same_morning(): void
    {
        $quiet = new QuietHours('21:00', '07:00', 'Africa/Nairobi');

        $this->assertSame('2026-10-20 07:00', $quiet->releaseAt($this->nairobi('2026-10-20 05:15'))->format('Y-m-d H:i'));
    }

    public function test_daytime_is_not_quiet(): void
    {
        $quiet = new QuietHours('21:00', '07:00', 'Africa/Nairobi');

        $this->assertNull($quiet->releaseAt($this->nairobi('2026-10-20 07:00')));
        $this->assertNull($quiet->releaseAt($this->nairobi('2026-10-20 12:00')));
        $this->assertNull($quiet->releaseAt($this->nairobi('2026-10-20 20:59')));
        $this->assertTrue($quiet->isQuiet($this->nairobi('2026-10-20 21:00')));
    }

    public function test_converts_from_utc(): void
    {
        $quiet = new QuietHours('21:00', '07:00', 'Africa/Nairobi');

        // 19:00 UTC is 22:00 in Nairobi (UTC+3).
        $release = $quiet->releaseAt(CarbonImmutable::parse('2026-10-20 19:00', 'UTC'));

        $this->assertSame('2026-10-21 04:00', $release->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    public function test_same_day_window(): void
    {
        $quiet = new QuietHours('13:00', '14:00', 'Africa/Nairobi');

        $this->assertSame('14:00', $quiet->releaseAt($this->nairobi('2026-10-20 13:30'))->format('H:i'));
        $this->assertNull($quiet->releaseAt($this->nairobi('2026-10-20 14:30')));
    }

    public function test_disabled_or_invalid_window_never_waits(): void
    {
        $this->assertNull((new QuietHours('21:00', '07:00', 'Africa/Nairobi', false))->releaseAt($this->nairobi('2026-10-20 23:00')));
        $this->assertNull((new QuietHours('nope', '07:00', 'Africa/Nairobi'))->releaseAt($this->nairobi('2026-10-20 23:00')));
        $this->assertNull((new QuietHours('07:00', '07:00', 'Africa/Nairobi'))->releaseAt($this->nairobi('2026-10-20 23:00')));
    }
}

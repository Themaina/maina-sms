<?php

namespace Maina888\Sms\Supports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Quiet hours window, e.g. 21:00 to 07:00 in the shop's timezone. Non-urgent SMS created inside
 * the window wait until it ends.
 */
class QuietHours
{
    public function __construct(
        protected string $start = '21:00',
        protected string $end = '07:00',
        protected string $timezone = 'UTC',
        protected bool $enabled = true,
    ) {
    }

    public function isQuiet(?CarbonInterface $at = null): bool
    {
        return $this->releaseAt($at) !== null;
    }

    /**
     * When a message created at $at may be sent, or null when it may be sent right away.
     * The returned time is in UTC-independent form (a Carbon instance in the configured timezone).
     */
    public function releaseAt(?CarbonInterface $at = null): ?CarbonImmutable
    {
        if (! $this->enabled) {
            return null;
        }

        [$startMinutes, $endMinutes] = [$this->minutes($this->start), $this->minutes($this->end)];

        if ($startMinutes === null || $endMinutes === null || $startMinutes === $endMinutes) {
            return null;
        }

        $local = CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($this->timezone);
        $now = $local->hour * 60 + $local->minute;

        [$endHour, $endMinute] = [intdiv($endMinutes, 60), $endMinutes % 60];

        if ($startMinutes < $endMinutes) {
            // Same-day window, e.g. 13:00 to 14:00.
            if ($now >= $startMinutes && $now < $endMinutes) {
                return $local->setTime($endHour, $endMinute);
            }

            return null;
        }

        // Overnight window, e.g. 21:00 to 07:00.
        if ($now >= $startMinutes) {
            return $local->addDay()->setTime($endHour, $endMinute);
        }

        if ($now < $endMinutes) {
            return $local->setTime($endHour, $endMinute);
        }

        return null;
    }

    protected function minutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }
}

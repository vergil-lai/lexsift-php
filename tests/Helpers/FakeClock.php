<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Tests\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use VergilLai\SensitiveText\Contracts\ClockInterface;

final class FakeClock implements ClockInterface
{
    private float $monotonic = 0.0;

    private DateTimeImmutable $wallTime;

    public function __construct()
    {
        $this->wallTime = new DateTimeImmutable('2026-09-21T00:00:00+00:00', new DateTimeZone('UTC'));
    }

    public function monotonic(): float
    {
        return $this->monotonic;
    }

    public function wallTime(): DateTimeImmutable
    {
        return $this->wallTime;
    }

    public function advance(float $seconds): void
    {
        $this->monotonic += $seconds;
        $this->wallTime = $this->wallTime->modify(sprintf(
            '%+d microseconds',
            (int) round($seconds * 1_000_000),
        ));
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Support;

use DateTimeImmutable;
use DateTimeZone;
use VergilLai\SensitiveText\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function wallTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

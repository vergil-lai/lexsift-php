<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Contracts;

use DateTimeImmutable;

interface ClockInterface
{
    public function monotonic(): float;

    public function wallTime(): DateTimeImmutable;
}

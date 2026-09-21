<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

enum Severity: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Critical = 4;
}

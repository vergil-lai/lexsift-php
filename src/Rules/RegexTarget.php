<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

enum RegexTarget: string
{
    case Original = 'original';
    case Normalized = 'normalized';
}

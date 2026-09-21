<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

enum WhitelistMode: string
{
    case Exact = 'exact';
    case Phrase = 'phrase';
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

enum Action: string
{
    case Allow = 'allow';
    case Flag = 'flag';
    case Review = 'review';
    case Block = 'block';

    public function rank(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Flag => 1,
            self::Review => 2,
            self::Block => 3,
        };
    }
}

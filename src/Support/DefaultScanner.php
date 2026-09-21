<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Support;

use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;

/** @internal */
final class DefaultScanner
{
    private static ?SensitiveText $scanner = null;

    public static function instance(): SensitiveText
    {
        return self::$scanner ??= SensitiveText::fromConfig(new SensitiveTextConfig());
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use VergilLai\SensitiveText\Result\ScanResult;

/**
 * @method static ScanResult scan(string $text)
 *
 * @see \VergilLai\SensitiveText\SensitiveText
 */
final class SensitiveText extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VergilLai\SensitiveText\SensitiveText::class;
    }
}

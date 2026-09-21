<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

final readonly class WhitelistRule
{
    public function __construct(
        public string $text,
        public WhitelistMode $mode = WhitelistMode::Phrase,
    ) {}
}

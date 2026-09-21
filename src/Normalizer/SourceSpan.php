<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Normalizer;

final readonly class SourceSpan
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}
}

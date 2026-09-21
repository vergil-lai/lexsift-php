<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

final readonly class SensitiveDictionary
{
    /**
     * @param list<SensitiveTerm> $terms
     */
    public function __construct(
        public string $version,
        public array $terms,
    ) {}
}

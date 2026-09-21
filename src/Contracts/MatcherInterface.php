<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Contracts;

use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Normalizer\NormalizedText;
use VergilLai\SensitiveText\Result\MatchResult;

interface MatcherInterface
{
    /** @return list<MatchResult> */
    public function match(NormalizedText $text, CompiledDictionary $dictionary): array;
}

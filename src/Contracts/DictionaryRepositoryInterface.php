<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Contracts;

use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;

interface DictionaryRepositoryInterface
{
    public function version(): string;

    public function load(): SensitiveDictionary;
}

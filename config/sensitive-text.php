<?php

declare(strict_types=1);

return [
    // Connection timeout/read_timeout belong in Laravel's config/database.php;
    // this package reuses the named connection and never mutates its shared client.
    'redis' => [
        'connection' => 'default',
        'prefix' => 'sensitive_text:',
    ],
    'dictionary' => [
        'key' => 'dictionary',
        'version_key' => 'dictionary:version',
    ],
    'normalizer' => [
        'unicode_nfkc' => true,
        'lowercase' => true,
        'remove_whitespace' => true,
        'remove_punctuation' => false,
        'remove_symbols' => false,
        'remove_emoji' => true,
        'remove_characters' => [],
    ],
    'version_check_interval' => 5.0,
    'mask_character' => '*',
    'regex_rules' => [],
    'whitelist' => [
        'rules' => [],
    ],
    'reload' => [
        'policy' => 'keep_last_good',
    ],
    'batch' => [
        'driver' => 'sync',
    ],
];

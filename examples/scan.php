<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use VergilLai\LexSift\Matcher;

$text = $argv[1] ?? '请加我微❤️信，远离赌博。';
$filter = new Matcher(
    terms: ['微信', '赌博'],
    whitelist: ['反赌博宣传'],
);

$matches = $filter->scan($text);

fwrite(STDOUT, json_encode([
    'contains' => $filter->contains($text),
    'masked' => $filter->mask($text),
    'matches' => $matches,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);

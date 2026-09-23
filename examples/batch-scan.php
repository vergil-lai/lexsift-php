<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use VergilLai\LexSift\Matcher;

$filter = new Matcher(
    terms: ['微信', '赌博'],
    whitelist: ['反赌博宣传'],
);
$texts = [
    'clean' => '这是一段正常文本。',
    'allowed' => '这是一段反赌博宣传。',
    'matched' => '请加我微❤️信。',
];

foreach ($texts as $key => $text) {
    fwrite(STDOUT, json_encode([
        'key' => $key,
        'contains' => $filter->contains($text),
        'masked' => $filter->mask($text),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
}

# LexSift PHP

LexSift PHP 是使用 PHP 实现的文本匹配库，基于 Aho-Corasick 算法一次匹配多个词条，适用于敏感词检测、关键词检索和文本替换。支持全角与半角转换、大小写统一、忽略空白等文本处理，并提供重叠匹配、短语白名单和原文脱敏。

通过 `VergilLai\LexSift\Matcher` 创建独立的匹配器实例。词库在构造或替换时编译，后续查询直接复用。

## 环境要求

- PHP 8.2 或以上。
- `ext-intl`，用于 Unicode 归一化。
- `ext-mbstring`，用于 UTF-8 校验和字符处理。

## 安装

发布后可通过 Composer 安装：

```sh
composer require vergil-lai/lexsift-php
```

## 使用示例

以下示例展示匹配、脱敏和更新词库：

```php
<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use VergilLai\LexSift\Matcher;

$filter = new Matcher(
    terms: ['赌博', '博彩', 'bad word', '微信'],
    whitelist: ['合法博彩说明', '微信支付'],
    options: ['lowercase' => true, 'remove_emoji' => true],
);

$text = '前赌 博后，微信支付';
var_dump($filter->contains($text));
print_r($filter->scan($text));
echo $filter->mask($text), "\n";

$filter->replaceTerms(['新的词语', '另一个词语']);
$filter->replaceWhitelist(['允许出现的完整短语']);
var_dump($filter->contains('新的词语'));
```

`scan()` 对 `赌 博` 的命中如下；空格属于原文匹配范围：

```php
[
    'term' => '赌博',
    'text' => '赌 博',
    'start' => 3,
    'end' => 10,
]
```

## API 与输入约定

| 方法 | 行为 |
| --- | --- |
| `__construct(array $terms, array $whitelist = [], array $options = [])` | 构建独立实例；未指定的选项使用默认值 |
| `contains(string $text): bool` | 找到首个未被白名单排除的匹配即停止匹配迭代 |
| `scan(string $text): array` | 返回所有有效重叠匹配 |
| `mask(string $text, string $replacement = '*'): string` | 合并有效原文范围后替换 |
| `replaceTerms(array $terms): void` | 完整替换当前实例词库 |
| `replaceWhitelist(array $whitelist): void` | 完整替换当前实例白名单 |

词库与白名单只接受字符串值，数组键不参与匹配，顺序采用 PHP 数组遍历顺序。空数组合法；空字符串及经过文本处理后为空的词抛出 `ValueError`。原始重复词和处理后相同的词均保留首次出现者，包括返回的原始 `term`。替换操作成功后立即生效，失败时保留旧状态，不影响其他实例。

所有文本入口，包括词库、白名单、正文和 replacement，必须为合法 UTF-8；损坏字节抛出 `ValueError`，不会被静默修复。类型错误抛出 `TypeError`；未知或非法 options 键抛出 `ValueError`。

## 文本处理选项

仅接受以下六个布尔选项；可只传其中部分，`0`、`1` 或字符串不代替布尔值。

| 选项 | 默认值 | 行为 |
| --- | --- | --- |
| `unicode_nfkc` | `true` | 统一字符形式（Unicode NFKC），包括全角转换、字符展开与组合 |
| `lowercase` | `true` | Unicode 逐码点小写转换 |
| `remove_whitespace` | `true` | 删除 Unicode 空白 |
| `remove_punctuation` | `false` | 删除 Unicode 标点 |
| `remove_symbols` | `false` | 删除 Unicode 符号 |
| `remove_emoji` | `true` | 按原始 grapheme 整簇删除 emoji |

词库、白名单和正文始终使用同一套文本处理规则。这些处理只用于匹配，返回的原词、命中文本和未命中的原文不会被改写。

先按原始 grapheme 移除 emoji，再执行全串 NFKC、逐码点小写转换和其他字符过滤。小写允许一字符展开为多字符；不执行 Unicode case folding、语言环境相关转换或希腊 final sigma 等上下文映射，也不删除变音符。

Emoji 使用宽泛规则：原始 grapheme 含 Extended_Pictographic、Emoji_Presentation、Emoji_Modifier、Regional_Indicator、VS16 或 keycap enclosing mark 时整簇删除，涵盖 ZWJ、肤色、旗帜及 keycap。普通数字、`#`、`*` 保留；`©`、`©︎`、`©️` 均会被删除。需要保留这些符号时，将 `remove_emoji` 设为 `false`。

Unicode 属性和归一化使用本机 ICU、PCRE、mbstring，具体字符支持取决于运行环境的 Unicode 数据版本。

## 白名单与字节偏移

白名单是普通字符串短语。经过文本处理后，敏感词匹配范围完整包含于**某一个**白名单匹配范围时才被忽略；部分重叠不豁免，多个白名单范围也不会联合形成豁免。例如 `微信支付` 可豁免其中的 `微信`，但白名单 `f` 不会豁免原文 `ﬁ` 经 NFKC 展开后的敏感词 `i`。

`scan()` 返回普通数组，每项只有 `term`、`text`、`start`、`end`。`term` 为词库原词，`text` 为命中的原文切片。偏移为原始 UTF-8 字符串的**字节偏移**，采用 `[start, end)`，保证 `substr($text, $start, $end - $start)` 等于匹配项的 `text`。

支持重叠，按 start 升序、同起点较长范围优先排列；相同范围的不同词按词库顺序排列。同一词映射到同一原文范围时只返回一次。位置映射覆盖完整来源 grapheme，因此组合字符不会被从中截断；跨越被移除字符时，这些内部字符也包含在匹配跨度中。独立的边缘被移除字符不会被扩大包含。

`mask()` 先排除白名单，再合并原文字节空间中重叠或相邻的有效范围，每个合并范围替换为**一次** replacement。空 replacement 表示删除，多字符 replacement 合法。替换不会修改未匹配原文，也不会因前面的替换导致后续偏移失效。

## 验证与 benchmark

在源码目录安装开发依赖，运行质量检查、测试和基准测试：

```sh
composer install
composer check
composer test:coverage
composer test:release
php benchmarks/worker.php 1000 100 3
```

测试覆盖参数校验、返回值、Unicode 映射、白名单和原子替换。覆盖率检查需要 PCOV 或 Xdebug。基准测试说明见 [性能文档](docs/performance.md)。

可运行示例位于 [examples](examples)：

```sh
php examples/scan.php '请勿参与赌博'
php examples/batch-scan.php
```

## PHP 扩展版本

本项目也提供 [LexSift PHP 扩展版本](https://github.com/vergil-lai/lexsift)，使用 Rust 实现匹配引擎。两个版本采用一致的方法、参数和返回值约定；扩展版本使用 `LexSift\Matcher`，本库使用 `VergilLai\LexSift\Matcher`。不同运行环境的 Unicode 数据版本可能导致个别字符的处理结果存在差异。

## 协议

[MIT](LICENSE)

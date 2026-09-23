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

| 方法                                                                    | 行为                                       |
| ----------------------------------------------------------------------- | ------------------------------------------ |
| `__construct(array $terms, array $whitelist = [], array $options = [])` | 构建独立实例；未指定的选项使用默认值       |
| `contains(string $text): bool`                                          | 找到首个未被白名单排除的匹配即停止匹配迭代 |
| `scan(string $text): array`                                             | 返回所有有效重叠匹配                       |
| `mask(string $text, string $replacement = '*'): string`                 | 合并有效原文范围后替换                     |
| `replaceTerms(array $terms): void`                                      | 完整替换当前实例词库                       |
| `replaceWhitelist(array $whitelist): void`                              | 完整替换当前实例白名单                     |

词库与白名单只接受字符串值，数组键不参与匹配，顺序采用 PHP 数组遍历顺序。空数组合法；空字符串及经过文本处理后为空的词抛出 `ValueError`。原始重复词和处理后相同的词均保留首次出现者，包括返回的原始 `term`。替换操作成功后立即生效，失败时保留旧状态，不影响其他实例。

所有文本入口，包括词库、白名单、正文和 replacement，必须为合法 UTF-8；损坏字节抛出 `ValueError`，不会被静默修复。类型错误抛出 `TypeError`；未知或非法 options 键抛出 `ValueError`。

## 文本处理选项

仅接受以下六个布尔选项；可只传其中部分，`0`、`1` 或字符串不代替布尔值。

| 选项                 | 默认值  | 行为                                                       |
| -------------------- | ------- | ---------------------------------------------------------- |
| `unicode_nfkc`       | `true`  | 统一字符形式（Unicode NFKC），包括全角转换、字符展开与组合 |
| `lowercase`          | `true`  | Unicode 逐码点小写转换                                     |
| `remove_whitespace`  | `true`  | 删除 Unicode 空白                                          |
| `remove_punctuation` | `false` | 删除 Unicode 标点                                          |
| `remove_symbols`     | `false` | 删除 Unicode 符号                                          |
| `remove_emoji`       | `true`  | 按原始 grapheme 整簇删除 emoji                             |

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

## 性能对比

同一台 macOS arm64 机器上实测，PHP 8.5.4；LexSift 扩展使用 [V0.1.0](https://github.com/vergil-lai/lexsift/releases/tag/v0.1.0)，LexSift PHP 使用 [V0.1.0](https://github.com/vergil-lai/lexsift-php/releases/tag/v0.1.0)。通过 `php -n` 隔离系统配置并显式加载扩展，关闭 CLI OPcache 和 JIT。两个实现使用相同的 10,000 词词库、默认文本处理选项；除标明白名单的场景外，均无白名单。

每场景预热 5 次，查询取 7 组均值的中位数，构建取 5 组中位数。查询每组按约 20 ms 自动选择 10–2,000 次迭代，构建每组 1 次；查询耗时不含构建，构建耗时包含对象生命周期。按“扩展 → PHP → PHP → 扩展”串行运行两轮，下表取两轮中位数的平均值，单位为 **毫秒/次**。加速比为 PHP 耗时除以扩展耗时。

| 场景                                     | LexSift 扩展 | LexSift PHP | 扩展加速比 |
| ---------------------------------------- | -----------: | ----------: | ---------: |
| 构建 10,000 词实例                       |      28.0201 |    132.3320 |     4.7 倍 |
| `scan()`：100 中文字，无命中             |       0.0093 |      0.2632 |    28.3 倍 |
| `scan()`：10,000 中文字，无命中          |       0.7120 |     17.3654 |    24.4 倍 |
| `scan()`：10,000 ASCII 字符，无命中      |       0.4309 |      2.6258 |     6.1 倍 |
| `scan()`：1,000 次命中                   |       0.6440 |     15.4235 |    23.9 倍 |
| `contains()`：长中文首部命中             |       0.0251 |     11.3638 |   453.4 倍 |
| `contains()`：长中文中部命中             |       0.3656 |     14.0836 |    38.5 倍 |
| `contains()`：长中文尾部命中             |       0.7118 |     16.8322 |    23.6 倍 |
| `contains()`：长中文无命中               |       0.7139 |     16.7897 |    23.5 倍 |
| `contains()`：长 ASCII 无命中            |       0.3768 |      2.3365 |     6.2 倍 |
| `contains()`：有白名单，首部有效命中     |       0.7133 |     17.2344 |    24.2 倍 |
| `contains()`：有白名单，唯一命中被豁免   |       0.7276 |     17.7609 |    24.4 倍 |
| `contains()`：白名单豁免后，尾部有效命中 |       0.7131 |     17.7839 |    24.9 倍 |
| `mask()`：1,000 个分隔的命中             |       0.6265 |     17.3728 |    27.7 倍 |

词库为 `敏感词00000` 至 `敏感词09999`，命中词为最后一项；中文填充字符为“文”，ASCII 为 `x`。长文本使用 10,000 个填充字符，首部、中部、尾部场景额外插入命中词。`scan()` 密集命中输入为命中词重复 1,000 次（8,000 码点、14,000 字节），`mask()` 场景在每个命中后加入“文”。白名单为 `敏感词09999safe`，用于对照有效命中、完全豁免和豁免后再次命中。

**约 453 倍仅对应长中文首部命中，不能代表整体性能。** 测试版本中，扩展在无白名单时可以流式处理并提前结束匹配；PHP 的非 ASCII 路径则先收集全文字符并完成 Unicode 组合，之后才开始匹配。两边实际处理的工作量不同。有白名单时，两边均需全文归一化；本次全文扫描的差距约为 6–28 倍。另一次长度对照中，首部命中后追加 100、1,000、10,000、30,000 个中文字，差距分别约为 19、137、472、586 倍，也说明该比值随文本长度变化。

本次对性能场景及全角、零宽字符、组合字符、白名单输入进行的 57 项 `contains()`、`scan()`、`mask()` 返回值对照均一致。两边均返回字节偏移，并对每个合并命中范围替换一次，因此表中的扫描和替换采用相同语义。这是固定合成输入的本机测试，未覆盖生产语料、内存、并发吞吐或开启 JIT 后的表现，不代表所有 Unicode 输入等价，也不是业务请求整体加速承诺。

## 协议

[MIT](LICENSE)

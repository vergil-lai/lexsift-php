请为我设计并实现一个现代化、可独立发布的 PHP 敏感词 / 文本匹配 Composer 包。

包名：

```text
vergil-lai/sensitive-text
```

目标不是复制传统的 `DFA sensitive filter`，而是实现一个可扩展、高性能、适合长期维护的文本匹配与敏感内容检测引擎。

核心采用：

```text
Text Normalization
+
Aho-Corasick Automaton
+
Regex Rules
+
Whitelist
+
Redis-backed Dictionary
+
Structured Match Results
```

同时提供 Laravel 可选集成，但核心包绝不能绑定 Laravel。

---

# 一、基础要求

* PHP >= 8.2
* Composer Package
* 包名：`vergil-lai/sensitive-text`
* Namespace：

```text
VergilLai\SensitiveText
```

* PSR-4
* 所有 PHP 文件使用：

```php
declare(strict_types=1);
```

* 核心代码不依赖 Laravel
* Laravel 仅作为 optional integration
* Redis 是必要运行组件
* 测试使用 Pest
* 静态分析使用 PHPStan
* 代码风格使用 PHP-CS-Fixer
* 公共 API 和复杂逻辑必须提供清晰 PHPDoc
* 核心模块必须可测试、可替换依赖
* 避免全局状态
* 避免隐藏副作用
* 优先 immutable design
* 必须考虑 Laravel Octane 和其他 long-running worker 环境
* 不要为了所谓高性能而滥用 Fiber、Fork 或复杂并发模型

可以使用 PHP 8.2 能力：

```text
readonly class
enum
constructor property promotion
union types
intersection types
match
attributes
first-class callable syntax
```

无需兼容 PHP 8.0 / 8.1。

---

# 二、Composer 安装

最终用户应可以：

```bash
composer require vergil-lai/sensitive-text
```

普通 PHP 项目直接使用：

```php
use VergilLai\SensitiveText\SensitiveText;

$scanner = SensitiveText::instance();

$result = $scanner->scan('请加我微❤️信联系');
```

Laravel 安装以后，应通过 Package Auto Discovery 自动注册 Service Provider。

---

# 三、项目定位

这个包不是简单的：

```text
DFA Filter
SensitiveWordHelper
str_replace filter
```

目标应该是：

```text
Reusable Text Matching Engine
+
Sensitive Content Detection Layer
```

设计时始终遵循：

```text
Detection != Business Decision

Normalizer != Matcher

Dictionary != Automaton

Matcher != Redis

Core != Laravel

Concurrency != Performance
```

优先级：

```text
正确性
>
可维护性
>
低误判
>
Worker 安全
>
性能
>
并发技巧
```

---

# 四、建议目录结构

可以根据实际实现调整，但整体建议：

```text
src/
├── Contracts/
├── Dictionary/
├── Matcher/
├── Normalizer/
├── Redis/
├── Result/
├── Rules/
├── Runtime/
├── Support/
├── Exception/
├── Laravel/
└── SensitiveText.php

config/
tests/
├── Unit/
├── Feature/
├── Integration/
└── Helpers/

benchmarks/
```

不要为了目录而目录。

如果某些模块非常简单，可以合理合并。

---

# 五、整体处理链路

核心流程：

```text
Original Text
    ↓
TextNormalizer
    ↓
NormalizedText
    ↓
AhoCorasickMatcher
    ↓
RegexMatcher
    ↓
Whitelist / Rules
    ↓
ScanResult
```

不要把所有职责堆到一个 Service 中。

---

# 六、核心类

至少考虑：

```text
SensitiveText
TextNormalizer
NormalizedText

SensitiveTerm
SensitiveDictionary
CompiledDictionary
DictionaryCompiler

DictionaryRepositoryInterface
RedisDictionaryRepository

AhoCorasickMatcher
AhoCorasickCompiler
RegexMatcher
WhitelistMatcher

MatcherInterface

ScanResult
MatchResult
ScannerStats

RedisClientInterface

RuntimeEnvironment
```

可根据实际设计增加：

```text
DictionaryVersion
RegexRule
WhitelistRule
NormalizerConfig
SensitiveTextConfig
BatchExecutorInterface
SyncBatchExecutor
ForkBatchExecutor
```

---

# 七、TextNormalizer

不要直接拿原文进行 AC 匹配。

必须：

```php
$normalized = $normalizer->normalize($text);
```

返回：

```text
NormalizedText
```

而不是简单字符串。

至少支持：

```text
Unicode NFKC
lowercase
whitespace normalization
punctuation handling
symbol handling
emoji handling
configurable character removal
```

Unicode 归一化使用：

```php
Normalizer::normalize(
    $text,
    Normalizer::FORM_KC
);
```

Composer 中要求：

```text
ext-intl
ext-mbstring
```

作为 required extension。

不要 silently fallback。

---

# 八、Normalizer 必须可配置

不要简单粗暴地删除全部标点和符号。

提供类似：

```php
final readonly class NormalizerConfig
{
    public function __construct(
        public bool $unicodeNfkc = true,
        public bool $lowercase = true,
        public bool $removeWhitespace = true,
        public bool $removePunctuation = true,
        public bool $removeSymbols = true,
        public bool $removeEmoji = true,
    ) {}
}
```

具体 API 可以优化。

重点是：

```text
Normalization policy
```

必须由配置控制。

例如：

```text
微 信
微-信
微_信
微❤️信
```

在适当配置下都可以归一化为：

```text
微信
```

但是像：

```text
C++
C#
foo.bar
```

这种内容不能因为默认规则设计错误而无条件破坏。

---

# 九、必须保留原文 Offset Mapping

这是核心能力。

例如：

```text
原文：

请加我微❤️信联系
```

Normalize 后：

```text
请加我微信联系
```

Matcher 命中：

```text
微信
```

最终：

```php
$match->matchedText
```

必须能够返回：

```text
微❤️信
```

而不是：

```text
微信
```

因此：

```php
final readonly class NormalizedText
{
    public function __construct(
        public string $original,
        public string $normalized,
        public array $offsetMap,
    ) {}
}
```

只是概念示例。

实际需要认真设计 offset map。

必须明确区分：

```text
byte offset
character offset
```

公共 API 尽量使用：

```text
character offset
```

而不是 PHP 字符串 byte offset。

---

# 十、Offset Mapping 要考虑 Unicode

测试和实现必须覆盖：

```text
中文
emoji
组合字符
多字节 UTF-8
全角字符
NFKC 发生字符数量变化
被删除的标点
连续删除字符
```

不要假设：

```text
1 Unicode code point = 1 byte
```

也不要简单地：

```php
strlen()
```

处理字符位置。

---

# 十一、核心算法使用 Aho-Corasick

关键词匹配核心必须使用：

```text
Aho-Corasick Automaton
```

不要只是实现普通 Trie 后，从每个字符重新扫描。

实现：

```text
Trie
+
Failure Links
+
Output States
```

目标时间复杂度：

```text
O(text length + match count)
```

支持：

```text
single match
multiple matches
prefix overlap
suffix overlap
nested terms
duplicate terms
```

例如：

```text
词库：

赌博
赌博平台
平台
```

扫描：

```text
这是赌博平台
```

应正确返回所有有效命中。

---

# 十二、不要错误命名为 DFA

不要创建：

```text
DfaFilter
DfaMatcher
DfaHelper
```

除非真正实现的是明确的 DFA abstraction。

这里核心明确命名：

```text
AhoCorasickCompiler
AhoCorasickMatcher
```

---

# 十三、Automaton 内部结构

特别注意 PHP Array 的内存开销。

不要直接使用巨大嵌套 associative array：

```php
[
    '赌' => [
        '博' => [
            ...
        ],
    ],
]
```

承载十万级词库。

优先考虑：

```text
node id
+
transition table
+
failure id
+
outputs
```

例如：

```php
$nodes = [
    0 => [
        'transitions' => [],
        'fail' => 0,
        'outputs' => [],
    ],
];
```

或者其他更 memory-efficient 的数据结构。

必须在：

```text
可维护性
性能
内存
```

之间取得平衡。

不要为了理论极限性能写出难以维护的数据结构。

---

# 十四、DictionaryCompiler

必须有独立：

```text
DictionaryCompiler
```

职责：

```text
Raw Terms
↓
Normalize
↓
Validate
↓
Deduplicate
↓
Build AC Automaton
↓
CompiledDictionary
```

Runtime Matcher 不负责词库编译。

不要在：

```php
scan()
```

中动态构造 Trie / Automaton。

---

# 十五、SensitiveTerm

使用 PHP 8.2 `readonly class`。

例如：

```php
final readonly class SensitiveTerm
{
    public function __construct(
        public string $term,
        public string $normalizedTerm,
        public string $category,
        public Severity $severity,
        public Action $action,
        public bool $enabled = true,
        public array $metadata = [],
    ) {}
}
```

实际结构可以根据设计调整。

---

# 十六、Enum

使用 PHP Enum。

例如：

```php
enum Action: string
{
    case Allow = 'allow';
    case Flag = 'flag';
    case Review = 'review';
    case Block = 'block';
}
```

以及：

```php
enum Severity: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Critical = 4;
}
```

不要使用：

```text
1
2
3
4
```

这样的 magic number 直接散落到业务代码。

---

# 十七、ScanResult

不要只返回：

```text
true / false
```

提供结构化结果。

至少支持：

```php
$result->matched();

$result->matches();

$result->count();

$result->highestSeverity();

$result->recommendedAction();

$result->shouldBlock();

$result->shouldReview();

$result->mask('*');
```

---

# 十八、MatchResult

建议：

```php
final readonly class MatchResult
{
    public function __construct(
        public string $term,
        public string $normalizedTerm,
        public string $matchedText,
        public string $category,
        public Severity $severity,
        public Action $action,
        public int $start,
        public int $end,
        public string $matcher,
        public array $metadata = [],
    ) {}
}
```

根据实际设计可以优化。

`start/end` 必须有清晰定义：

```text
原文 character offset
```

或者提供明确独立字段，避免 ambiguity。

---

# 十九、Mask

例如：

```text
原文：

请加我微❤️信联系
```

Normalize：

```text
请加我微信联系
```

命中：

```text
微信
```

调用：

```php
$result->mask('*');
```

必须基于原文：

```text
微❤️信
```

的位置进行替换。

不要修改 normalized text 后返回。

必须正确处理：

```text
single match
multiple matches
overlap
nested matches
adjacent matches
emoji
UTF-8
```

需要定义 overlap 时的 mask 策略。

---

# 二十、RegexMatcher

除了 AC Matcher，提供：

```text
RegexMatcher
```

用途：

```text
联系方式
URL
WX / VX
Telegram
银行卡
账号格式
特殊文本模式
```

职责明确：

```text
Aho-Corasick
=
大量固定关键词

Regex
=
少量复杂规则
```

不要：

```text
把十万个关键词拼成一个巨大 Regex
```

---

# 二十一、RegexRule

可以考虑：

```php
final readonly class RegexRule
{
    public function __construct(
        public string $pattern,
        public string $category,
        public Severity $severity,
        public Action $action,
        public array $metadata = [],
    ) {}
}
```

必须验证 regex 合法性。

非法 Regex 应抛出明确异常。

---

# 二十二、Whitelist

提供独立：

```text
WhitelistMatcher
```

或者其他合理规则层。

不要把 whitelist 硬编码到 AC Matcher。

至少考虑：

```text
exact whitelist
phrase whitelist
context whitelist
```

例如：

```text
敏感词：

博彩
```

但：

```text
反博彩宣传
```

可能属于允许表达。

第一版不需要做非常复杂的 NLP Context Engine。

但架构应该允许以后扩展。

---

# 二十三、Matcher Interface

建议：

```php
interface MatcherInterface
{
    /**
     * @return list<MatchResult>
     */
    public function match(
        NormalizedText $text,
        CompiledDictionary $dictionary,
    ): array;
}
```

具体参数可以优化。

未来应该可以扩展：

```text
AhoCorasickMatcher
RegexMatcher
PinyinMatcher
SemanticMatcher
LLMMatcher
```

但是 V1 不实现：

```text
Pinyin
同音字 AI
Embedding
Semantic Search
LLM Moderation
Machine Learning
```

---

# 二十四、Redis 是必要组件

这个包要求依赖并使用 Redis。

Redis 用于：

```text
dictionary storage
dictionary version
dictionary invalidation
distributed coordination
```

但是核心匹配算法不能直接依赖某个具体 Redis Client。

---

# 二十五、RedisClientInterface

提供：

```text
RedisClientInterface
```

例如：

```php
interface RedisClientInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;
}
```

根据实际功能扩展。

不要让：

```text
Predis
ext-redis
Laravel Redis Facade
```

直接散落在 Domain / Matcher 中。

---

# 二十六、Redis 驱动

考虑支持：

```text
predis/predis
ext-redis
```

通过 Adapter：

```text
PredisClientAdapter
PhpRedisClientAdapter
```

如果需要 Laravel Redis，则：

```text
LaravelRedisAdapter
```

只能出现在 Laravel Integration。

---

# 二十七、Composer Redis 设计

请先分析后决定：

```text
predis/predis
```

是否作为 required dependency。

倾向：

```text
predis/predis
```

作为 Composer 默认可用 Redis Client。

同时支持：

```text
ext-redis
```

作为更高性能的可选 Driver。

如果有更合理设计，可以调整，但：

```text
用户安装 composer package 后必须能够清晰地配置 Redis
```

不要 silent fallback。

---

# 二十八、DictionaryRepositoryInterface

例如：

```php
interface DictionaryRepositoryInterface
{
    public function version(): string;

    /**
     * @return list<SensitiveTerm>
     */
    public function terms(): array;

    public function reload(): SensitiveDictionary;
}
```

实际 API 可以优化。

提供：

```text
RedisDictionaryRepository
```

---

# 二十九、Redis Key

默认 key 可以类似：

```text
sensitive_text:dictionary
sensitive_text:dictionary:version
sensitive_text:dictionary:updated
```

必须可以配置 prefix。

不要硬编码成无法修改。

---

# 三十、Dictionary Versioning

Redis 中维护：

```text
sensitive_text:dictionary:version
```

词库变化：

```text
version++
```

进程内保存：

```text
local compiled version
```

如果：

```text
redisVersion !== localVersion
```

则：

```text
reload terms
↓
compile new dictionary
↓
replace local compiled dictionary
```

不要每个 request rebuild Automaton。

---

# 三十一、Redis Version Check Interval

不要每次：

```php
scan()
```

都：

```text
Redis GET dictionary version
```

否则：

```text
1000 QPS
```

可能额外造成：

```text
1000 Redis GET/s
```

只是为了检查版本。

增加：

```text
version_check_interval
```

例如：

```text
1
5
10
30 seconds
```

进程内保存：

```text
lastVersionCheckAt
```

超过 interval 才检查 Redis。

默认值请合理设置。

---

# 三十二、Redis Pub/Sub

可以支持：

```text
sensitive_text:dictionary:updated
```

Redis Pub/Sub。

用途：

```text
dictionary invalidation
```

但是：

```text
Pub/Sub 不允许成为唯一更新机制
```

因为：

```text
worker restart
network reconnect
missed message
```

都有可能丢失事件。

因此：

```text
Pub/Sub
+
version polling fallback
```

更合理。

V1 如果 Pub/Sub 会明显增加复杂度，可以先做好 abstraction 而不强行完成实时 listener。

---

# 三十三、Compiled Dictionary 缓存

不要默认将完整：

```text
Aho-Corasick Automaton
```

PHP serialize 后放入 Redis。

先分析：

```text
serialization cost
network cost
memory
unserialize cost
PHP version coupling
security
compatibility
```

默认倾向：

```text
Redis
=
dictionary data
+
version
+
metadata

Process Memory
=
Compiled Automaton
```

除非 benchmark 证明序列化 Automaton 更有优势。

---

# 三十四、Redis 故障策略

如果 Redis 暂时不可用：

```text
Redis unavailable
```

但 Worker 已经有：

```text
last known good compiled dictionary
```

则：

```text
继续使用已有 dictionary
```

不要让已有的敏感词检测全部失败。

如果：

```text
first boot
+
no local dictionary
+
Redis unavailable
```

才抛出明确异常。

---

# 三十五、Singleton

要求提供：

```php
SensitiveText::instance();
```

或者：

```php
SensitiveText::getInstance();
```

作为方便 API。

同一个普通 PHP Process 生命周期中：

```text
Repository
Normalizer
Matcher
Compiled Dictionary
```

不应反复初始化。

---

# 三十六、Singleton 不可污染架构

不要做成传统不可测试 Singleton：

```text
private static
new inside class
hardcoded Redis
global mutable state
```

要求：

```text
Convenient Singleton Entry Point
+
Dependency Injection Friendly Core
```

也就是说：

```php
SensitiveText::instance()
```

只是默认入口。

同时允许：

```php
new SensitiveText(
    normalizer: $normalizer,
    repository: $repository,
    matchers: $matchers,
);
```

便于测试和自定义。

---

# 三十七、Singleton 中允许保存什么

可以保存：

```text
configuration
repository
Redis adapter
normalizer
matcher
compiled dictionary
dictionary version
version check timestamp
```

绝对不能保存：

```text
current request
current text
current cursor
current matches
current user
ScanResult
request context
```

---

# 三十八、Matcher 必须无请求状态

例如：

```php
public function match(NormalizedText $text): array
{
    $state = 0;
    $matches = [];

    // scanning
}
```

要求：

```text
state
matches
offset
cursor
```

全部为 method-local state。

不要保存成：

```php
$this->state
$this->matches
$this->currentText
```

---

# 三十九、Long-running Worker

必须重点适配：

```text
Laravel Octane
Swoole
OpenSwoole
RoadRunner
FrankenPHP
```

不要假设：

```text
请求结束
=
Process 结束
```

Automaton 应该：

```text
Worker 内存常驻
```

流程：

```text
Worker Start / First Use
↓
Load Dictionary
↓
Compile Automaton
↓
Keep in Process Memory
↓
Reuse Across Requests
```

---

# 四十、Octane 状态安全

必须模拟：

```text
scan A
scan B
scan C
```

使用同一个 Singleton / Matcher。

验证不存在：

```text
A 的 text 泄漏到 B
A 的 matches 泄漏到 B
A 的 cursor 泄漏到 B
A 的 offset map 泄漏到 B
```

共享对象应该尽量：

```text
immutable
```

---

# 四十一、Dictionary Reload

刷新词库时：

```text
old immutable compiled dictionary
↓
build new dictionary independently
↓
success
↓
replace reference
```

不要：

```text
边扫描
边修改现有 Automaton
```

如果重新编译失败：

```text
继续使用 old last-known-good dictionary
```

---

# 四十二、Fiber

核心扫描路径：

```text
禁止使用 Fiber
```

明确：

```text
Fiber MUST NOT be used inside scan().
```

原因：

```text
Aho-Corasick scan is CPU-bound.
Fiber does not make CPU-bound matching faster.
```

不要：

```text
一个 Matcher 一个 Fiber
一个词一个 Fiber
一段文本一个 Fiber
一个 Automaton segment 一个 Fiber
```

---

# 四十三、Octane + Fiber

如果运行于：

```text
Swoole
OpenSwoole
```

并发应由宿主：

```text
Coroutine / Event Loop
```

处理。

Library 不应建立自己的 Fiber Scheduler。

原则：

```text
运行时负责并发
Library 负责单次 scan 足够快
```

核心 library：

```text
concurrency agnostic
```

---

# 四十四、spatie/fork

请评估：

```text
spatie/fork
```

但只能作为 optional dependency。

适用：

```text
CLI batch processing
offline scanning
大量独立文本
离线数据清理
```

不适用：

```text
普通 HTTP 请求
单文本扫描
Octane Worker 中动态 fork
```

Composer 可：

```json
{
    "suggest": {
        "spatie/fork": "Optional parallel processing for CLI batch scanning"
    }
}
```

---

# 四十五、Octane 内禁止自动 Fork

如果运行在：

```text
Octane
Swoole
OpenSwoole
RoadRunner
FrankenPHP
```

普通 HTTP Request 内：

```text
不要自动 pcntl_fork
不要自动 spatie/fork
```

因为可能造成：

```text
Redis connection duplication
database connection duplication
event loop corruption
file descriptor inheritance
worker lifecycle bugs
unexpected process state
```

---

# 四十六、RuntimeEnvironment

设计：

```text
RuntimeEnvironment
```

识别：

```text
CLI
FPM
Swoole
OpenSwoole
RoadRunner
FrankenPHP
```

Laravel / Octane-specific detection 放在：

```text
Laravel integration
```

不要让 Core Package 依赖 Laravel 来判断 runtime。

---

# 四十七、Batch Processing

如果实现：

```text
BatchExecutorInterface
```

建议：

```php
interface BatchExecutorInterface
{
    public function scan(iterable $texts): iterable;
}
```

默认：

```text
SyncBatchExecutor
```

可选：

```text
ForkBatchExecutor
```

V1 不要实现：

```text
FiberBatchExecutor
```

除非 benchmark 明确证明实际有收益。

---

# 四十八、Laravel Integration

核心包不能依赖 Laravel。

提供：

```text
src/Laravel/SensitiveTextServiceProvider.php
```

Composer Package Auto Discovery：

```json
{
    "extra": {
        "laravel": {
            "providers": [
                "VergilLai\\SensitiveText\\Laravel\\SensitiveTextServiceProvider"
            ]
        }
    }
}
```

---

# 四十九、Laravel Service Provider

Provider 注册：

```php
$this->app->singleton(
    \VergilLai\SensitiveText\SensitiveText::class,
    function ($app) {
        // build package
    }
);
```

Laravel 中应该直接复用：

```text
Singleton
```

以及 long-running worker 能力。

---

# 五十、Laravel Config

提供：

```text
config/sensitive-text.php
```

至少包含：

```text
redis driver
redis connection
redis prefix

dictionary key
dictionary version key

normalizer options

version check interval

mask character

regex rules
whitelist settings

reload policy

batch processing
```

不要把：

```text
业务敏感词
```

直接全部硬编码在 config 文件。

---

# 五十一、Laravel Facade

可以提供：

```text
VergilLai\SensitiveText\Laravel\Facades\SensitiveText
```

例如：

```php
$result = SensitiveText::scan($content);
```

Facade 只能是：

```text
Laravel Adapter
```

核心包仍然：

```php
$scanner->scan($content);
```

---

# 五十二、Laravel Octane Integration

Provider 必须考虑：

```text
Laravel Octane
```

可以：

```text
Lazy Initialization
```

例如：

```text
first scan
↓
load Redis dictionary
↓
compile
↓
reuse
```

这是非常合理的默认方式。

如果能够可靠使用：

```text
WorkerStarting
```

等生命周期事件，也可以初始化。

但：

```text
不要为了预加载而引入复杂的 Octane lifecycle bug
```

优先简单可靠。

---

# 五十三、Laravel 不允许污染 Core

以下内容禁止出现在 Core：

```text
Illuminate\Support\Facades\Redis
app()
config()
resolve()
Container
Facade
Octane classes
Laravel events
```

这些只能存在于：

```text
src/Laravel/
```

---

# 五十四、普通 PHP API

目标：

```php
use VergilLai\SensitiveText\SensitiveText;

$scanner = SensitiveText::instance();

$result = $scanner->scan('请加我微❤️信联系');

if ($result->matched()) {
    foreach ($result->matches() as $match) {
        echo $match->term;
        echo $match->matchedText;
        echo $match->category;
        echo $match->severity->name;
    }
}
```

Mask：

```php
echo $result->mask('*');
```

---

# 五十五、Laravel API

```php
use VergilLai\SensitiveText\SensitiveText;

$result = app(SensitiveText::class)
    ->scan($content);
```

或者：

```php
use VergilLai\SensitiveText\Laravel\Facades\SensitiveText;

$result = SensitiveText::scan($content);
```

---

# 五十六、Observability

提供轻量：

```text
ScannerStats
```

例如：

```php
$scanner->stats();
```

可以返回：

```text
dictionary version
term count
automaton node count
last compile duration
last reload time
estimated memory usage
version last checked time
```

不要绑定：

```text
Prometheus
OpenTelemetry
Laravel Telescope
```

只暴露基础统计。

---

# 五十七、Exception Design

提供明确异常层级：

```text
SensitiveTextException
DictionaryException
DictionaryCompileException
RedisUnavailableException
InvalidRuleException
NormalizationException
InvalidConfigurationException
```

不要所有错误都直接：

```php
throw new RuntimeException();
```

---

# 五十八、Pest

测试框架使用：

```text
Pest
```

Composer dev dependency：

```text
pestphp/pest
```

测试应优先使用 Pest Style。

例如：

```php
it('detects sensitive terms', function () {
    $result = scanner()->scan('这是赌博平台');

    expect($result->matched())
        ->toBeTrue();
});
```

可以使用：

```text
expect()
dataset()
beforeEach()
afterEach()
```

---

# 五十九、Pest 测试目录

建议：

```text
tests/
├── Unit/
│   ├── Normalizer/
│   ├── Matcher/
│   ├── Dictionary/
│   └── Result/
│
├── Feature/
│   └── SensitiveTextTest.php
│
├── Integration/
│   ├── Redis/
│   └── Laravel/
│
└── Helpers/
```

可以合理调整。

---

# 六十、Normalizer Tests

使用 Pest dataset。

至少测试：

```text
NFKC
全角字符
半角字符
uppercase
lowercase
whitespace
punctuation
symbols
emoji
Chinese
English
mixed language
```

例如：

```php
dataset('normalization cases', [
    ['ＷＥＣＨＡＴ', 'wechat'],
    ['WeChat', 'wechat'],
    ['微 信', '微信'],
]);
```

---

# 六十一、Offset Mapping Tests

重点覆盖：

```text
原文：

请加我微❤️信联系
```

Normalize：

```text
请加我微信联系
```

命中：

```text
微信
```

结果：

```php
$match->matchedText
```

必须：

```text
微❤️信
```

额外测试：

```text
emoji
multiple emoji
Unicode combining characters
NFKC character conversion
连续删除字符
头部删除
尾部删除
中文多字节
英文
mixed text
```

---

# 六十二、AC Automaton Tests

覆盖：

```text
single term
multiple terms
prefix overlap
suffix overlap
nested terms
duplicate terms
empty dictionary
no match
Chinese
English
mixed text
```

例如：

```text
赌博
赌博平台
平台
```

扫描：

```text
这是赌博平台
```

验证全部预期 MatchResult。

---

# 六十三、Regex Tests

覆盖：

```text
match
no match
multiple matches
invalid pattern
overlap with AC result
Unicode regex
```

---

# 六十四、Whitelist Tests

覆盖：

```text
exact whitelist
phrase whitelist
whitelist removes expected match
whitelist does not remove unrelated match
overlapping whitelist
```

---

# 六十五、Mask Tests

覆盖：

```text
single match
multiple matches
nested match
overlapping match
adjacent match
emoji
UTF-8
normalized / original mismatch
```

---

# 六十六、Redis Unit Tests

Core Unit Tests 不要依赖真实 Redis。

使用：

```text
FakeRedisClient
FakeDictionaryRepository
Stub / Fake
```

测试：

```text
version reading
dictionary loading
version changed
reload
reload failure
fallback
```

---

# 六十七、Redis Integration Tests

真实 Redis 测试放：

```text
tests/Integration/Redis
```

通过环境变量决定是否执行。

例如：

```text
SENSITIVE_TEXT_REDIS_TESTS=1
```

没有 Redis 时：

```text
普通 Unit Tests 必须照常运行
```

---

# 六十八、Singleton Tests

测试：

```php
expect(SensitiveText::instance())
    ->toBe(SensitiveText::instance());
```

同时测试：

```text
Dependency Injection instance
```

不会被 static singleton 污染。

不要为了测试增加不合理的：

```php
SensitiveText::resetEverythingForTests()
```

public API。

如果需要，可以设计：

```text
internal/testing API
```

或合理 lifecycle。

---

# 六十九、Long-running Worker Tests

模拟：

```text
create scanner once

scan A
scan B
scan C
scan D
```

确保：

```text
state 不泄漏
matches 不泄漏
offset 不泄漏
current text 不泄漏
```

再模拟：

```text
dictionary v1
↓
scan
↓
dictionary changed
↓
compile v2
↓
scan
```

确保 atomic replacement 正确。

---

# 七十、Pest Laravel Integration Tests

如果测试：

```text
SensitiveTextServiceProvider
```

使用：

```text
orchestra/testbench
```

作为：

```text
require-dev
```

不要让核心 Package 依赖 Laravel。

通过 Pest + Testbench 测：

```text
Provider registration
singleton binding
config merge
config publishing
Facade
Redis adapter mapping
Octane-safe singleton lifecycle
```

---

# 七十一、PHPStan

必须使用：

```text
phpstan/phpstan
```

目标：

```text
level: max
```

如果某些第三方类型问题导致无法合理达到 max，可以使用：

```text
level: 9
```

但必须明确原因。

默认目标：

```text
PHPStan max
```

---

# 七十二、PHPStan Config

创建：

```text
phpstan.neon.dist
```

至少：

```neon
parameters:
    level: max

    paths:
        - src
        - tests
```

可根据需要配置。

最终：

```text
0 PHPStan errors
```

---

# 七十三、PHPStan 类型要求

不要滥用：

```text
mixed
array
```

例如：

```php
/**
 * @return list<MatchResult>
 */
public function matches(): array
```

以及：

```php
/**
 * @var array<int, int>
 */
private array $offsetMap;
```

例如 transition：

```php
/**
 * @var array<string, int>
 */
private array $transitions;
```

例如：

```php
/**
 * @var list<SensitiveTerm>
 */
private array $terms;
```

让 PHPStan 能完整理解：

```text
Automaton
Dictionary
OffsetMap
MatchResult
Redis payload
```

的数据结构。

---

# 七十四、禁止逃避 PHPStan

不要随便使用：

```text
baseline
ignoreErrors
@phpstan-ignore
@phpstan-ignore-next-line
mixed
```

来让检查通过。

只有确认：

```text
第三方库 stub 有问题
Laravel 特定 dynamic behavior 无法合理表达
```

时才允许：

```text
最小范围
+
明确注释原因
```

的 ignore。

---

# 七十五、PHP-CS-Fixer

使用：

```text
friendsofphp/php-cs-fixer
```

创建：

```text
.php-cs-fixer.php
```

以现代 PHP 8.2 风格配置。

基础建议：

```text
@PER-CS
```

可以额外考虑：

```text
declare_strict_types
ordered_imports
no_unused_imports
single_quote
trailing_comma_in_multiline
array_syntax
binary_operator_spaces
blank_line_after_opening_tag
blank_line_after_namespace
fully_qualified_strict_types
native_function_invocation
```

但不要为了规则数量而启用极端风格。

可读性优先。

---

# 七十六、Composer Scripts

在：

```text
composer.json
```

至少提供：

```json
{
    "scripts": {
        "test": "pest",
        "test:coverage": "pest --coverage",
        "stan": "phpstan analyse",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "cs:fix": "php-cs-fixer fix",
        "check": [
            "@cs",
            "@stan",
            "@test"
        ]
    }
}
```

根据 Composer 实际 executable resolution 调整。

最终应该可以：

```bash
composer test
composer stan
composer cs
composer cs:fix
composer check
```

---

# 七十七、Composer Dev Dependencies

至少：

```text
pestphp/pest
phpstan/phpstan
friendsofphp/php-cs-fixer
```

Laravel integration test：

```text
orchestra/testbench
```

只能：

```text
require-dev
```

如果 benchmark 使用单独库，请谨慎增加依赖。

---

# 七十八、Coverage

支持：

```bash
composer test:coverage
```

如果环境安装：

```text
Xdebug
PCOV
```

即可生成 coverage。

普通：

```bash
composer test
```

不能因为没有 coverage driver 而失败。

目标：

```text
>= 85%
```

但是：

```text
不要为了 coverage 写无意义测试
```

重点覆盖：

```text
Normalizer
Offset Mapping
AhoCorasickCompiler
AhoCorasickMatcher
Mask
Dictionary reload
Redis failure fallback
Singleton lifecycle
Long-running worker safety
```

---

# 七十九、Benchmark

创建：

```text
benchmarks/
```

至少测试词库规模：

```text
1,000
10,000
50,000
100,000
```

文本长度：

```text
100 chars
1,000 chars
10,000 chars
```

指标：

```text
dictionary normalization time
compile time
compiled node count
memory usage
warm scan time
cold scan time
matches per second
dictionary reload time
```

---

# 八十、Batch Benchmark

如果实现：

```text
ForkBatchExecutor
```

必须 benchmark：

```text
Sync Batch
vs
Fork Batch
```

测试例如：

```text
100 texts
1,000 texts
10,000 texts
```

只有实际有收益时：

```text
README 推荐 Fork
```

否则保持：

```text
Sync default
```

---

# 八十一、Memory Benchmark

重点关注：

```text
PHP associative array overhead
```

测试：

```text
10k terms
50k terms
100k terms
```

输出：

```text
raw terms memory
compiled automaton memory
memory per node
memory per term
```

可以据此调整 Automaton 内部结构。

---

# 八十二、README

必须提供完整 README：

```text
Installation
Requirements
Quick Start
Architecture
Redis Setup
Dictionary Format
Normalization
Aho-Corasick
Regex Rules
Whitelist
Mask
Dictionary Reload
Laravel Usage
Octane Usage
Batch Scanning
Performance
Worker Safety
Error Handling
Testing
PHPStan
PHP-CS-Fixer
Benchmark
```

明确说明：

```text
Fiber is not used in the core scan path.
```

以及：

```text
Fork-based processing is intended for CLI/offline batch workloads only.
```

---

# 八十三、README Laravel

至少包含：

```bash
composer require vergil-lai/sensitive-text
```

以及：

```php
$result = app(
    \VergilLai\SensitiveText\SensitiveText::class
)->scan($content);
```

Facade 示例。

配置发布命令如果提供，也写出来。

---

# 八十四、README Octane

明确说明：

```text
Compiled Automaton is reused inside long-running workers.
```

同时说明：

```text
request-specific state is never stored on singleton services.
```

并说明：

```text
dictionary version polling
```

和：

```text
reload strategy
```

的行为。

---

# 八十五、V1 明确不做

第一版不要做：

```text
Pinyin Matching
Homophone AI
Embedding
Vector Search
Semantic Search
LLM Moderation
Machine Learning
Distributed Automaton Compilation
复杂 NLP
```

但接口允许未来增加。

---

# 八十六、不要过度设计

不要为了“高级”而：

```text
给每个类都建 Interface
建立复杂 Event Bus
引入 CQRS
引入 DDD Aggregate
引入 Message Queue
引入复杂 Plugin Manager
引入 Fiber Scheduler
引入自己实现的 IoC Container
```

只有存在真实 abstraction boundary 时才增加 Interface。

---

# 八十七、重点关注性能的位置

真正值得优化：

```text
Normalizer
Unicode iteration
Offset Mapping
Automaton representation
Automaton scan
Dictionary compile
Dictionary reload
Redis version check
```

不值得提前优化：

```text
一堆 Factory
一堆 Builder
Fiber
不必要的 Fork
过度缓存
```

---

# 八十八、代码注释

公共 API：

```text
清晰 PHPDoc
```

复杂算法：

```text
解释为什么
```

不要写大量这种无价值注释：

```php
// Increment index.
$index++;

// Return result.
return $result;
```

重点解释：

```text
failure links
offset mapping
Unicode normalization
overlap strategy
dictionary atomic swap
long-running worker safety
```

---

# 八十九、开发顺序

不要一次性把所有代码生成完。

按照以下顺序实现：

1. 分析完整架构
2. 输出目录结构
3. 确定 Composer dependencies
4. 创建 composer.json
5. 配置 Pest
6. 配置 PHPStan
7. 配置 PHP-CS-Fixer
8. 创建 Exceptions
9. 创建 Enums
10. 创建 Value Objects / DTO
11. 创建 Contracts
12. 实现 NormalizedText
13. 实现 TextNormalizer
14. 实现 Offset Mapping
15. 为 Normalizer 写 Pest tests
16. 为 Offset Mapping 写 Pest tests
17. 实现 SensitiveTerm
18. 实现 SensitiveDictionary
19. 实现 AhoCorasickCompiler
20. 实现 AhoCorasickMatcher
21. 编写 AC Pest tests
22. 执行 PHPStan
23. 执行 PHP-CS-Fixer check
24. 创建 ScanResult
25. 创建 MatchResult
26. 实现 Mask
27. 编写 Mask tests
28. 实现 RegexMatcher
29. 编写 Regex tests
30. 实现 Whitelist
31. 编写 Whitelist tests
32. 设计 RedisClientInterface
33. 实现 Predis adapter
34. 实现 PhpRedis adapter
35. 实现 RedisDictionaryRepository
36. 实现 Dictionary Versioning
37. 实现 Redis failure fallback
38. 编写 Redis Unit Tests
39. 编写 Redis Integration Tests
40. 实现 SensitiveText main service
41. 实现 Singleton Entry Point
42. 编写 Singleton tests
43. 编写 Long-running worker tests
44. 实现 RuntimeEnvironment
45. 实现 Laravel Provider
46. 实现 Laravel Config
47. 实现 Laravel Facade
48. 使用 Pest + Testbench 测 Laravel Integration
49. 检查 Octane compatibility
50. 评估 BatchExecutor
51. 评估 spatie/fork
52. 编写 B


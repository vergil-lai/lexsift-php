# Sensitive Text V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 构建可独立发布的 `vergil-lai/sensitive-text`，提供 Unicode 原文映射、AC/Regex 检测、白名单、Redis 词库更新和可选 Laravel 集成。

**Architecture:** 核心是同步、无请求状态的匹配流水线；归一化与编译在独立对象中完成，已编译词库通过不可变快照复用。Redis repository 提供一致的版本化词库，主服务只负责惰性初始化、限频检查和成功后替换快照。Laravel 只做配置、连接和容器适配，不进入核心依赖链。

**Tech Stack:** PHP >=8.2、ext-intl、ext-mbstring、ext-redis（phpredis）、Composer/PSR-4、Pest、PHPStan max、PHP-CS-Fixer、Testbench。

**Spec:** `docs/superpowers/specs/2026-09-21-sensitive-text-original.md`（用户附件原文；第八十九节末尾原本截断在“52. 编写 B”）。本计划以下“设计约定”补齐可执行语义，供用户审阅。

## Global Constraints

- PHP >= 8.2；无需兼容 PHP 8.0 / 8.1。
- 包名：`vergil-lai/sensitive-text`；Namespace：`VergilLai\SensitiveText`；PSR-4。
- 所有 PHP 文件使用：`declare(strict_types=1);`。
- 核心代码不依赖 Laravel；Laravel 仅作为 optional integration。
- Redis 是必要运行组件；Composer 要求 `ext-intl`、`ext-mbstring`、`ext-redis`，缺少任一扩展时阻止安装，不提供 Redis 客户端 fallback。
- 测试使用 Pest；静态分析使用 PHPStan，默认目标：`PHPStan max`；最终 `0 PHPStan errors`。
- 代码风格使用 PHP-CS-Fixer；公共 API 和复杂逻辑必须提供清晰 PHPDoc。
- `Detection != Business Decision`；`Normalizer != Matcher`；`Dictionary != Automaton`；`Matcher != Redis`；`Core != Laravel`；`Concurrency != Performance`。
- 正确性 > 可维护性 > 低误判 > Worker 安全 > 性能 > 并发技巧。
- `Fiber MUST NOT be used inside scan().`；不建立 Fiber Scheduler。
- 普通 HTTP Request 内不要自动 `pcntl_fork`，不要自动 `spatie/fork`。
- request-specific state is never stored on singleton services.
- 不默认将完整 Automaton PHP serialize 后放入 Redis。
- 初次无可用词库时失败必须抛异常；已有 last-known-good dictionary 时刷新失败继续使用旧词库。
- 不使用 baseline、ignoreErrors 或 phpstan-ignore 掩盖自身类型错误。
- Coverage 目标 `>= 85%`；普通测试不要求 coverage driver。
- V1 不做 Pinyin Matching、Homophone AI、Embedding、Vector Search、Semantic Search、LLM Moderation、Machine Learning、Distributed Automaton Compilation、复杂 NLP。
- 不引入 CQRS、Event Bus、消息队列、Plugin Manager、自建 IoC Container。

## Review Focus

- NFKC 一变多、多变一和组合符重排不能使命中切断原文字符簇；Task 2/4 测试 `ﬃ`、`e\u{0301}`、Hangul Jamo 和重排组合符。
- Redis 写入竞争或读取途中版本变化不能生成“v2 标识 + v1 内容”；Task 7 测试 CAS 冲突和原子读取快照。
- 归一化后空词不能匹配所有位置；无效 UTF-8、全删除输入必须具有确定行为；Task 2/3 测试异常与空输入。
- Regex 零宽匹配、PCRE 运行时失败不能悄悄当作无命中；Task 5 测试跳过零宽结果和显式错误。
- 重叠白名单不能清空整篇文本的其他命中；并发刷新不能倒退词库；Task 6/9 测试局部包含与刷新重入。

---

## 范围、环境与设计约定

### 当前事实与阶段边界

2026-09-21 检查：工作目录 `/Users/vergil/projects/sensitive-text` 为空，没有 `.git`、已有代码或 `.codegraph/`。本机 PHP 8.5.4、Composer 2.9.5。本次仅生成计划和原始需求副本，没有安装项目依赖、初始化 Git、编写产品代码或执行产品测试。

这个需求可分成核心引擎、Redis 生命周期、Laravel 适配三份子计划；建议当前保留一份总计划及三个验收阶段，因为它们共享 `NormalizedText`、`CompiledDictionary` 和 `SensitiveText` 接口。Task 1–6 交付可用内存词库测试的核心，Task 7–10 交付普通 PHP Redis 包，Task 11–13 交付可选 Laravel 适配与发布材料。不要同时铺开所有模块。

提交步骤是实施时的检查点，不是本次执行授权。目录尚无 Git：实施时先确认仓库身份；需要本地提交且仍为空目录时初始化本地仓库。没有远端地址或推送授权时不创建远端、不 push、不发布 Packagist、不打正式版本 tag。若用户只批准编码不批准提交，保留各检查点差异并报告即可。

### 冻结的 V1 语义

1. 所有公开 `start/end` 是原文 Unicode **code point** 索引，零起点、半开区间 `[start,end)`，不是 byte 或 grapheme 数量。内部单独维护原文 UTF-8 byte 边界表。
2. 每个归一化输出 code point 映射到原文一个完整扩展字符簇范围；NFKC 展开的多个字符允许共享范围。命中范围取覆盖全部参与输出的原文最小区间，内部删除的字符被包含，首尾未参与匹配的删除字符不被包含。
3. 默认开启 NFKC、lowercase、去空白和去 emoji，保留标点与一般符号。因此示例 `微❤️信` 命中，`C++`、`C#`、`foo.bar` 不被默认破坏。`NormalizerConfig::aggressive()` 显式打开去标点和符号。`removeCharacters` 是额外删除的单 code point 列表；字典与输入共用同一个配置。
4. lowercase 定义为逐归一化 code point 的 `mb_strtolower(..., 'UTF-8')`，不做 casefold、不做音译。显式避免 PHP 版本间整句 contextual sigma 规则改变公共语义；希腊 `ΟΣ` 产生 `οσ`。将该区别写入 README。
5. emoji 按原文字符簇判定后整体删除：Extended_Pictographic、双 Regional_Indicator、keycap 序列；包含 variation selector / ZWJ / skin tone 的整簇删除，不能单独留下装饰符；普通数字不是 emoji。附测试固定这些分类。
6. 空输入和空词库合法；启用的空词、非法 UTF-8、归一化后空词使编译失败；disabled 词直接跳过。完全重复记录去重；相同 normalizedTerm 但 category/severity/action/metadata 不同的记录全部保留，不静默覆盖风险信息。
7. `MatchResult` 保留原始 term、normalizedTerm、原文 matchedText、category、Severity、Action、start/end、matcher、metadata。matcher 固定 `aho_corasick` 或 `regex`。结果按 start、end、matcher、term 排序，完全相同结果去重，跨 matcher 的结果保留。
8. `highestSeverity(): ?Severity` 空时返回 null；`recommendedAction(): Action` 空时 Allow；优先级 Block > Review > Flag > Allow。`shouldBlock()` 只表示建议 Block；`shouldReview()` 只表示建议 Review，Block 时 false。不执行任何业务阻断。
9. Mask 先合并重叠或相邻区间，按原文 code point 数量重复一个掩码字符。`mask('*')` 对 `微❤️信` 产生四个 `*`（心与 VS16 是两个 code points）；空掩码允许删除，多 code point 掩码拒绝。不对 normalizedText 做替换。
10. Regex 默认运行在 original，可显式选择 normalized；规则创建时校验 `/u` 和合法性，运行时错误抛 `InvalidRuleException`，零宽结果忽略。V1 沿用 `preg_match_all` 的规则内非重叠匹配；AC、不同规则之间可重叠。
11. 白名单 Exact 指“命中区间的归一化文本等于白名单”，Phrase 指“命中区间被完整包含于白名单短语的一次出现”。只删符合条件的命中；部分交叉不删。context V1 用显式 Phrase 表达，如 `反博彩宣传`，不声称语义理解。
12. Redis 默认使用 `sensitive_text:dictionary`、`sensitive_text:dictionary:version`。dictionary 值为 JSON `{schema:1,terms:[...]}`；版本为递增非负十进制字符串，初版 `1`。合法空库与缺 key 区分；缺任一 key 不视为空库。
13. 数据和版本通过同一 Lua 脚本原子读取、CAS 原子更新，比较 expectedVersion 后以十进制字符串加一，最后一次 MSET 写入版本和数据；冲突不覆盖。V1 使用单 Redis primary/Sentinel primary 连接，不支持 Cluster 的跨槽默认 key；Cluster 不列为支持范围，不偷偷修改 key 协议。
14. 自动检查默认 5 秒，时钟使用单调时间。失败检查也更新时间以避免故障期间重试风暴。`reload()` 手动强制刷新并在失败时抛异常，同时保留旧快照；自动刷新记录失败并继续旧词库。`invalidate()` 只使下一次检查立即到期，可由宿主 Pub/Sub 回调调用。
15. 首次 scan 可调用独立编译器初始化；warm scan 禁止重新编译。这是将需求“不要在 scan 中构造 Automaton”解释为“不让 Matcher/逐次扫描承担编译”，与需求允许 first-scan lazy initialization 一致。
16. 方便入口用独立 `Support/DefaultScanner.php` 持有唯一进程级实例；DI 构造不读/写它。`SensitiveText::instance()` 委托该入口；`SensitiveText::fromConfig()` 每次创建独立实例。默认 Redis 地址显式为 `tcp://127.0.0.1:6379`、1 秒连接/读写超时，可通过配置指定 TLS URL；不扫描全局环境变量、不隐式读取 Laravel 配置。构造时不连网，首次 scan 连接。
17. V1 提供 `SyncBatchExecutor`，保留输入 key，懒执行；不实现 ForkBatchExecutor、不增加 spatie/fork 运行依赖。评估理由和未来启用标准写入性能文档。运行时检测只提供建议，不作为允许 fork 的安全判断。
18. 常驻服务持有不可变快照和生命周期状态；请求文本、offset、结果全部局部变量。reload guard 在任何 Redis I/O 前设置；有旧快照的并发请求立即使用旧快照，无旧快照的重入显式抛 `DictionaryException`，由宿主重试，不创建自有调度器。

### 依赖与证据

候选约束：生产 `php:>=8.2`、`ext-intl:*`、`ext-mbstring:*`、`ext-redis:*`。保留需求指定的 PHP 下限；文档只声称真实矩阵验证过的版本，不把开放的 Composer 约束视为未来 PHP 兼容性证明。Redis 客户端仅支持 phpredis，缺少扩展时 Composer 阻止安装，不提供用户态客户端 fallback；Illuminate 与 Testbench 仅 dev/suggest。

开发：`pestphp/pest:^3.0 || ^4.0`、`phpstan/phpstan:^2.0`、`friendsofphp/php-cs-fixer:^3.0`、`orchestra/testbench:^10.0 || ^11.0`。执行时 Composer solver 和真实 PHP 矩阵为最终证据；不得使用 `--ignore-platform-reqs`、禁用安全阻断来求解。Laravel 12 + Testbench 10 是 PHP 8.2 基线，Laravel 13 + Testbench 11 是较新 PHP 集成目标；后者须在 Task 11 用实际 package metadata/solver 确認再宣称支持。

- [Composer schema](https://getcomposer.org/doc/04-schema.md)：生产、开发和建议依赖独立；已通过 Context7 `/composer/composer` 核对 PSR-4 与 platform requirements。
- [Pest 支持策略](https://pestphp.com/docs/support-policy) 与 [Pest 3 升级指南](https://pestphp.com/docs/upgrade-guide)：Pest 3 支持 PHP 8.2；PHP 8.2 作兼容验证通道，较新 PHP 用受支持的新主版本，不将旧工具版本锁给全部环境。
- [Testbench 版本映射](https://packages.tools/testbench)：官方表明确 Laravel 12 对应 Testbench 10；新 Laravel 通道需追加实际验证。
- [PHP Normalizer](https://www.php.net/manual/en/normalizer.normalize.php)：归一化返回 `string|false`，错误需显式处理，不能以空串替代。
- [spatie/fork 官方仓库](https://github.com/spatie/fork)：作为 CLI 离线并行候选；V1 没有实测收益，故不实现、不推荐 HTTP 内使用。

## 文件结构与接口归属

以下列出的类各自同名文件，均属于 `VergilLai\SensitiveText` 下对应子命名空间。只在契约处建立 interface，不为每个 DTO/算法额外建立接口。

```text
composer.json                 包元数据、依赖、脚本、Laravel discovery
phpunit.xml.dist              Pest suites、src coverage
phpstan.neon.dist             max、src/tests/config/benchmarks
.php-cs-fixer.php             PER-CS、strict types、导入、数组风格
.gitignore / .gitattributes   测试产物和发行归档规则
src/
  Exception/{SensitiveTextException,DictionaryException,DictionaryCompileException,
             RedisUnavailableException,InvalidRuleException,NormalizationException,
             InvalidConfigurationException}.php
  Rules/{Severity,Action,RegexTarget,WhitelistMode,RegexRule,WhitelistRule}.php
  Normalizer/{NormalizerConfig,SourceSpan,NormalizedText,TextNormalizer}.php
  Dictionary/{SensitiveTerm,SensitiveDictionary,CompiledDictionary,DictionaryCompiler,
              RedisDictionaryRepository,DictionaryJsonCodec}.php
  Matcher/{AhoCorasickCompiler,AhoCorasickMatcher,RegexMatcher,WhitelistMatcher}.php
  Result/{MatchResult,ScanResult,ScannerStats}.php
  Contracts/{MatcherInterface,DictionaryRepositoryInterface,RedisClientInterface,
             ClockInterface,BatchExecutorInterface}.php
  Redis/PhpRedisClientAdapter.php
  Runtime/{RuntimeEnvironment,SyncBatchExecutor}.php
  Support/{SystemClock,DefaultScanner}.php
  SensitiveTextConfig.php / SensitiveText.php
  Laravel/{SensitiveTextServiceProvider,LaravelRedisAdapter}.php
  Laravel/Facades/SensitiveText.php
config/sensitive-text.php
tests/
  Pest.php
  Helpers/{FakeClock,FakeRepository,FakeRedisClient}.php
  Unit/{Normalizer,Matcher,Dictionary,Result,Redis,Runtime}/
  Feature/{SensitiveTextTest,SingletonTest,WorkerLifecycleTest}.php
  Integration/Redis/RedisDictionaryRepositoryTest.php
  Integration/Laravel/{TestCase,ProviderTest}.php
benchmarks/{run,worker}.php
README.md / LICENSE / CHANGELOG.md
docs/{dictionary-protocol,performance,release-checklist}.md
.github/workflows/ci.yml
```

`LICENSE` 计划采用 MIT，作者 Vergil Lai；作为发布元数据随计划审阅。不同授权要求在实施前修改该项。所有代码块中短类名在所属文件按上述位置 `use` 导入；每个实际 PHP 文件补 `<?php`、strict_types 和 namespace，测试文件只需 strict_types 与 use。测试辅助类只在 tests，不能作为生产 fallback。

## Task 1: 可安装包与类型化领域模型

**Files:** Create `composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.php`, `.gitignore`, `tests/Pest.php`; Create `src/Exception/` 下上表七个文件、`src/Rules/Severity.php`, `src/Rules/Action.php`, `src/Dictionary/SensitiveTerm.php`; Test `tests/Unit/Dictionary/SensitiveTermTest.php`。

**Interfaces:** Produces `Severity:int` Low=1/Medium=2/High=3/Critical=4；`Action:string` Allow/Flag/Review/Block；`Action::rank():int`。`SensitiveTerm::__construct(string $term,string $category='default',Severity $severity=Severity::Medium,Action $action=Action::Flag,bool $enabled=true,array $metadata=[])`。metadata 采用 PHPStan 递归 JSON 值别名或嵌套明确的 `array<string, scalar|null|array<array-key,scalar|null>>`，V1 最多两层；拒绝对象与资源，不使用任意 mixed 传播。

- [ ] **Step 1: 写 Composer 和质量工具配置，安装依赖。** `require` 按上节，必须直接要求 `ext-redis:*`，不加入用户态 Redis 客户端或缺扩展 fallback；`autoload` 为 `VergilLai\\SensitiveText\\ => src/`，dev 为 `VergilLai\\SensitiveText\\Tests\\ => tests/`，`config.allow-plugins.pestphp/pest-plugin=true`，不设置全局 platform 假版本。`scripts` 使用：

```json
{
  "test": "pest",
  "test:coverage": "pest --coverage --min=85",
  "stan": "phpstan analyse --memory-limit=1G",
  "cs": "php-cs-fixer fix --dry-run --diff",
  "cs:fix": "php-cs-fixer fix",
  "check": ["@cs", "@stan", "@test"]
}
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true" cacheDirectory=".phpunit.cache">
  <testsuites><testsuite name="all"><directory>tests/Unit</directory><directory>tests/Feature</directory><directory>tests/Integration</directory></testsuite></testsuites>
  <source><include><directory>src</directory></include></source>
</phpunit>
```

```neon
parameters:
    level: max
    paths:
        - src
        - tests
    tmpDir: .phpstan-cache
```

```php
$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests']);
return (new PhpCsFixer\Config())->setRiskyAllowed(true)->setRules([
    '@PER-CS' => true,
    'declare_strict_types' => true,
    'ordered_imports' => true,
    'no_unused_imports' => true,
    'single_quote' => true,
    'array_syntax' => ['syntax' => 'short'],
])->setFinder($finder);
```

建立空测试目录以便 Finder 工作；`.gitignore` 含 `/vendor/`、`/.phpunit.cache/`、`/.phpstan-cache/`、`/.php-cs-fixer.cache`、`/coverage/`、`/benchmarks/results/`。库的 `composer.lock` 不提交，CI 分别解析最低/最新可兼容依赖。

Run: `composer update --prefer-dist`；随后 `composer validate --strict`、`composer check-platform-reqs`。Expected: 安装成功且没有忽略平台要求；若依赖不兼容，记录 solver 原始冲突，调整 dev 约束而不提高核心 PHP 下限。

- [ ] **Step 2: 写失败领域测试。**

```php
it('keeps immutable term metadata and action order', function () {
    $term = new SensitiveTerm('赌博', 'gambling', Severity::High, Action::Block);
    expect($term->term)->toBe('赌博')
        ->and($term->enabled)->toBeTrue()
        ->and(Action::Block->rank())->toBeGreaterThan(Action::Review->rank());
    expect(fn () => $term->term = 'changed')->toThrow(Error::class);
});
```

Run: `vendor/bin/pest tests/Unit/Dictionary/SensitiveTermTest.php`；Expected: 缺类而失败。

- [ ] **Step 3: 实现 enum、readonly term、异常树。** `SensitiveTextException extends RuntimeException`；DictionaryException 继承它，DictionaryCompileException/RedisUnavailableException 继承 DictionaryException，其余直接继承基础异常。term 原文 UTF-8/空值最终在编译器验证；DTO 构造仅检查 category 非空及 metadata 类型。

```php
public function rank(): int
{
    return match ($this) {
        self::Allow => 0, self::Flag => 1, self::Review => 2, self::Block => 3,
    };
}
```

- [ ] **Step 4: 运行测试、PHPStan、风格检查。** `composer cs:fix && composer check`。Expected: 领域测试通过；不生成 baseline。
- [ ] **Step 5: 精确暂存 Task 1 文件，提交检查点。** `git commit -m "feat: 建立包骨架和领域类型"`，提交前 `git diff --cached --stat` 核对范围。

## Task 2: Unicode 归一化与原文映射

**Files:** Create `src/Normalizer/NormalizerConfig.php`, `SourceSpan.php`, `NormalizedText.php`, `TextNormalizer.php`; Test `tests/Unit/Normalizer/TextNormalizerTest.php`, `OffsetMappingTest.php`。

**Interfaces:** Produces readonly `SourceSpan(int $start,int $end)`；readonly `NormalizerConfig(bool $unicodeNfkc=true,bool $lowercase=true,bool $removeWhitespace=true,bool $removePunctuation=false,bool $removeSymbols=false,bool $removeEmoji=true,array $removeCharacters=[])`，`aggressive():self`；`TextNormalizer::__construct(NormalizerConfig $config=new NormalizerConfig())`、`normalize(string):NormalizedText`。`NormalizedText` 保存 original、normalized、`list<string> $characters`、`list<SourceSpan> $offsetMap`、`list<int> $originalByteOffsets`；`span(int $start,int $end):SourceSpan`、`sliceOriginal(SourceSpan):string`、`normalizedRangeForOriginal(int $start,int $end):string`。后者返回与原文区间有交集的归一化字符，供 original regex/exact whitelist 使用；不构造空映射伪位置。

- [ ] **Step 1: 写归一化 dataset 和失败测试。**

```php
dataset('normalization', [
    ['ＷＥＣＨＡＴ', 'wechat'], ['WeChat', 'wechat'], ['微 信', '微信'],
    ['微❤️信', '微信'], ['C++ C# foo.bar', 'c++c#foo.bar'],
    ["e\u{0301}", 'é'], ['ﬃ', 'ffi'], ["\u{1100}\u{1161}", '가'],
    ['ΟΣ', 'οσ'], ['', ''], [" \t\n❤️", ''], ['１２3', '123'],
    ['微👨‍👩‍👧‍👦🇨🇳1️⃣信', '微信'], ['中English文', '中english文'],
]);
it('normalizes deterministically', function (string $input, string $output) {
    expect((new TextNormalizer())->normalize($input)->normalized)->toBe($output);
})->with('normalization');
it('rejects invalid utf8', function () {
    expect(fn () => (new TextNormalizer())->normalize("\xFF"))
        ->toThrow(NormalizationException::class);
});
it('keeps original intervals through expansion and deletion', function () {
    $n = (new TextNormalizer())->normalize('请加我微❤️信联系');
    $span = $n->span(3, 5);
    expect([$span->start, $span->end])->toBe([3, 7])
        ->and($n->sliceOriginal($span))->toBe('微❤️信');
    $n = (new TextNormalizer())->normalize('ﬃ');
    expect($n->sliceOriginal($n->span(1, 2)))->toBe('ﬃ');
});
```

Run: `vendor/bin/pest tests/Unit/Normalizer`；Expected: 缺类失败。

- [ ] **Step 2: 实现配置和原文 byte 边界索引。** 先 `mb_check_encoding($text,'UTF-8')`，再 `preg_match_all('/./us',...)` 建立 code point 及 byte 边界；`preg_match_all('/\X/u',...)` 建字符簇及其原文 code point 区间。逐字符累计 `strlen($point)` 只用于 byte 表；公开位置绝不使用 strlen。

```php
public function span(int $start, int $end): SourceSpan
{
    if ($start < 0 || $end <= $start || $end > count($this->offsetMap)) {
        throw new NormalizationException('Invalid normalized interval');
    }
    $selected = array_slice($this->offsetMap, $start, $end - $start);
    return new SourceSpan(
        min(array_map(static fn (SourceSpan $s): int => $s->start, $selected)),
        max(array_map(static fn (SourceSpan $s): int => $s->end, $selected)),
    );
}
public function sliceOriginal(SourceSpan $span): string
{
    return substr($this->original, $this->originalByteOffsets[$span->start],
        $this->originalByteOffsets[$span->end] - $this->originalByteOffsets[$span->start]);
}
```

- [ ] **Step 3: 实现带来源信息的 NFKC，不能逐原文 code point 单独 FORM_KC 后拼接。** TextNormalizer 内部使用 `array{char:string,span:SourceSpan,ccc:int,ordinal:int}` 原子，处理顺序：
  1. 每原文字符簇做 FORM_KD，给分解结果继承整簇 span；得到组合类别 `IntlChar::getCombiningClass(mb_ord($char,'UTF-8'))`。
  2. 对所有原子的连续非 starter 段按 ccc、ordinal 稳定排序，不能跨 starter；此步包含兼容分解造成的跨簇边界。避免逐步插入导致长组合符串平方复杂度。
  3. 按 Unicode canonical composition 维护 starter index 与 lastClass；未被 blocking 的原子尝试 FORM_C(starter+char)，恰好一个 code point 才合并，并把 span 取包络。成功合并不更新 lastClass，未合并则更新；ccc=0 新设 starter，Hangul L+V、LV+T 同样通过 pair composition 合并。
  4. 按约定逐输出 point lowercase，可能展开，继承其 span。按原文簇标记删除 emoji，再按配置过滤 whitespace/P/S/额外字符；lowercase 展开的每个 point 都携带来源。
  5. `unicodeNfkc=false` 时直接遍历原始簇内 points；仍保留同样 offset 语义。任何 Normalizer false / PCRE false 抛 NormalizationException。

组合决策的实现核心：

```php
$candidate = ($starter !== null && ($lastClass === 0 || $lastClass < $atom['ccc']))
    ? \Normalizer::normalize($out[$starter]['char'].$atom['char'], \Normalizer::FORM_C)
    : null;
if ($candidate === false) {
    throw new NormalizationException('Unicode composition failed');
}
if ($candidate !== null && mb_strlen($candidate, 'UTF-8') === 1) {
    $old = $out[$starter]['span'];
    $out[$starter]['char'] = $candidate;
    $out[$starter]['span'] = new SourceSpan(min($old->start, $atom['span']->start), max($old->end, $atom['span']->end));
} else {
    if ($atom['ccc'] === 0) {
        $starter = count($out);
    }
    $out[] = $atom;
    $lastClass = $atom['ccc'];
}
```

内部 span 查询先用可读实现；对长输出的包络扫描成本在 Task 12 测量，不能宣称整体 normalization+mapping 为严格 O(n)。AC 状态转换维持其自身线性目标。

- [ ] **Step 4: 添加 NFKC 差分和位置回归。** 用不 lowercase、不删除的 config 对测试样本比较整个 ICU FORM_KC；emoji 默认保留开关另测。

```php
it('agrees with whole-string ICU normalization', function () {
    $normalizer = new TextNormalizer(new NormalizerConfig(
        lowercase: false, removeWhitespace: false, removeEmoji: false,
    ));
    foreach (["a\u{0315}\u{0300}", "\u{1100}\u{1161}\u{11A8}", 'ｶﾞ', '㍍ﬃ', 'Å'] as $s) {
        expect($normalizer->normalize($s)->normalized)
            ->toBe(\Normalizer::normalize($s, \Normalizer::FORM_KC));
    }
});
it('does not absorb removed edges', function () {
    $n = (new TextNormalizer(NormalizerConfig::aggressive()))->normalize(' ❤️微---信❤️ ');
    expect($n->sliceOriginal($n->span(0, 2)))->toBe('微---信');
});
```

另用 dataset 覆盖 removePunctuation/removeSymbols/removeEmoji 各自开关、`removeCharacters:['x']`、多个 emoji、全角标点、ASCII 数字和中文混合、组合符簇的一变多。所有删字符开关关闭时不删除字符。

- [ ] **Step 5: 测试及静态分析通过，提交。** `vendor/bin/pest tests/Unit/Normalizer && composer stan && composer cs`；精确暂存上述六个文件，`git commit -m "feat: 实现 Unicode 归一化和原文位置映射"`。

## Task 3: 词库校验和 Aho-Corasick 编译

**Files:** Create `src/Dictionary/SensitiveDictionary.php`, `CompiledDictionary.php`, `DictionaryCompiler.php`, `src/Matcher/AhoCorasickCompiler.php`; Test `tests/Unit/Dictionary/DictionaryCompilerTest.php`。

**Interfaces:** `SensitiveDictionary(string $version,array $terms)` readonly，terms 为 list<SensitiveTerm>；`DictionaryCompiler(TextNormalizer $normalizer,AhoCorasickCompiler $automaton=new AhoCorasickCompiler())::compile(SensitiveDictionary):CompiledDictionary`。后者 readonly，字段 version、terms、`list<string> normalizedTerms`、`list<int> termLengths`、`list<array<string,int>> transitions`、`list<int> failures`、`list<list<int>> outputs`、`list<int|null> outputLinks`、normalizationSeconds、compileSeconds、estimatedMemoryBytes。outputs 只含该节点直接终止词 ID；outputLinks 指向最近有输出的 failure 祖先，避免 suffix 输出全量复制。

`AhoCorasickCompiler::compile(array $normalizedTerms):array` 返回明确 shape `{transitions:list<array<string,int>>,failures:list<int>,outputs:list<list<int>>,outputLinks:list<int|null>}`。transition key 使用 `'u:'.$char`，避免 PHP 将数字字符 key 自动变 int。

- [ ] **Step 1: 写校验/重复词测试，运行红灯。**

```php
it('deduplicates identical records but preserves different policies', function () {
    $a = new SensitiveTerm('WeChat');
    $b = new SensitiveTerm('wechat', severity: Severity::High);
    $d = (new DictionaryCompiler(new TextNormalizer()))->compile(new SensitiveDictionary('1', [$a, $a, $b]));
    expect($d->normalizedTerms)->toBe(['wechat', 'wechat'])
        ->and($d->termLengths)->toBe([6, 6]);
});
it('rejects enabled terms normalized to empty', function () {
    expect(fn () => (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', [new SensitiveTerm('❤️')])))
        ->toThrow(DictionaryCompileException::class);
});
it('supports an intentionally empty dictionary', function () {
    $d = (new DictionaryCompiler(new TextNormalizer()))->compile(new SensitiveDictionary('1', []));
    expect($d->transitions)->toBe([[]])->and($d->failures)->toBe([0]);
});
```

Run: `vendor/bin/pest tests/Unit/Dictionary/DictionaryCompilerTest.php`；Expected: 缺类失败。

- [ ] **Step 2: 编译器 normalize/validate/deduplicate，再构造扁平 node tables。** 完全重复判断包含原文 term、标准化 term、分类、级别、建议动作和递归排序后的 metadata；相同词不同元数据不丢失。失败包装 DictionaryCompileException 并保留 previous。compiled 数组不暴露可变节点对象。

```php
$state = 0;
foreach (mb_str_split($term, 1, 'UTF-8') as $char) {
    $key = 'u:'.$char;
    if (!isset($transitions[$state][$key])) {
        $next = count($transitions);
        $transitions[$state][$key] = $next;
        $transitions[] = [];
        $failures[] = 0;
        $outputs[] = [];
        $outputLinks[] = null;
    }
    $state = $transitions[$state][$key];
}
$outputs[$state][] = $termId;
```

- [ ] **Step 3: 用 SplQueue BFS 构造 failure/output links。** root 的每个孩子 fail=0 入队；对边 parent→child 追 parent.failure 直到存在同字符转移或 root，赋 child.failure；output link 为该 fail 若有 outputs，否则继承其 outputLink。只在编译中修改表。

```php
$f = $failures[$parent];
while ($f !== 0 && !isset($transitions[$f][$key])) {
    $f = $failures[$f];
}
$failures[$child] = $transitions[$f][$key] ?? 0;
$target = $failures[$child];
$outputLinks[$child] = $outputs[$target] !== [] ? $target : $outputLinks[$target];
```

- [ ] **Step 4: 增加 disabled、invalid UTF-8、数字字符串、后缀链回归并检查。** 用 `[he,she,hers,his]` 校验 `she` 的 failure 能到 `he`；用 `[a,aa,aaa]` 验证只存三个直接 output ID，未复制成六个。`composer check` Expected: 全通过。
- [ ] **Step 5: 精确暂存本任务文件，提交。** `git commit -m "feat: 实现词库编译和 AC 失败链接"`。

## Task 4: AC 匹配、结构化结果和 Mask

**Files:** Create `src/Contracts/MatcherInterface.php`, `src/Matcher/AhoCorasickMatcher.php`, `src/Result/MatchResult.php`, `ScanResult.php`; Test `tests/Unit/Matcher/AhoCorasickMatcherTest.php`, `tests/Unit/Result/ScanResultTest.php`。

**Interfaces:** `MatcherInterface::match(NormalizedText $text,CompiledDictionary $dictionary):array` → list<MatchResult>。readonly `MatchResult(string $term,string $normalizedTerm,string $matchedText,string $category,Severity $severity,Action $action,int $start,int $end,string $matcher,array $metadata=[])`。readonly `ScanResult(string $original,array $matches)` 对外方法为设计约定中的八个 API；`matches():array` 是 list<MatchResult>。本任务 tests 直接编译调用 matcher，不依赖后续主服务。

- [ ] **Step 1: 写完整多命中/映射测试，运行红灯。**

```php
it('emits nested and suffix matches with original ranges', function () {
    $normalizer = new TextNormalizer();
    $d = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('赌博'), new SensitiveTerm('赌博平台'), new SensitiveTerm('平台'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalizer->normalize('这是赌博平台'), $d);
    $r = new ScanResult('这是赌博平台', $matches);
    expect(array_map(static fn (MatchResult $m) => [$m->term, $m->start, $m->end], $r->matches()))
        ->toBe([['赌博', 2, 4], ['赌博平台', 2, 6], ['平台', 4, 6]])
        ->and($r->mask('*'))->toBe('这是****');
});
it('masks original codepoints including removed emoji', function () {
    $n = new TextNormalizer();
    $d = (new DictionaryCompiler($n))->compile(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $s = '请加我微❤️信联系';
    $r = new ScanResult($s, (new AhoCorasickMatcher())->match($n->normalize($s), $d));
    expect($r->matches()[0]->matchedText)->toBe('微❤️信')
        ->and($r->mask())->toBe('请加我****联系');
});
```

Run: `vendor/bin/pest tests/Unit/Matcher tests/Unit/Result`；Expected: 缺类失败。

- [ ] **Step 2: 实现局部 state 扫描与 outputLink 遍历。** 每个字符仅推进或追 failure，不从每个开始位置重扫。对每个 output ID 用 termLengths 计算 normalized start，再 `span()` 映射，填充 MatchResult。

```php
$state = 0;
$matches = [];
foreach ($text->characters as $i => $char) {
    $key = 'u:'.$char;
    while ($state !== 0 && !isset($dictionary->transitions[$state][$key])) {
        $state = $dictionary->failures[$state];
    }
    $state = $dictionary->transitions[$state][$key] ?? 0;
    for ($node = $state; $node !== null; $node = $dictionary->outputLinks[$node]) {
        foreach ($dictionary->outputs[$node] as $id) {
            $span = $text->span($i + 1 - $dictionary->termLengths[$id], $i + 1);
            $term = $dictionary->terms[$id];
            $matches[] = new MatchResult($term->term, $dictionary->normalizedTerms[$id],
                $text->sliceOriginal($span), $term->category, $term->severity,
                $term->action, $span->start, $span->end, 'aho_corasick', $term->metadata);
        }
    }
}
return $matches;
```

- [ ] **Step 3: 实现 ScanResult 排序、建议策略和区间 Mask。** 构造验证每个结果落在原文边界且 matchedText 与原文切片一致；先排序再去完全重复。Mask 计算区间并拼接原文片段，避免逐次 str_replace 改变后续 offset。

```php
foreach ($this->matches as $match) {
    $last = array_key_last($ranges);
    if ($last !== null && $match->start <= $ranges[$last][1]) {
        $ranges[$last][1] = max($ranges[$last][1], $match->end);
    } else {
        $ranges[] = [$match->start, $match->end];
    }
}
$cursor = 0;
$result = '';
foreach ($ranges as [$start, $end]) {
    $result .= mb_substr($this->original, $cursor, $start - $cursor, 'UTF-8');
    $result .= str_repeat($mask, $end - $start);
    $cursor = $end;
}
return $result.mb_substr($this->original, $cursor, null, 'UTF-8');
```

Mask 参数先检查 UTF-8 且 mb_strlen<=1；默认 `'*'`。结果建议动作以 rank 最大者返回，级别独立取最大值。

- [ ] **Step 4: 加数据驱动回归并过检查。** cases：无命中、空库、`he/she/hers/his`、`a/aa/aaa`、同词不同 policy、中文英文数字混合、单个命中、重叠/嵌套/相邻 mask、`ﬃ` 内两个 normalized 命中仅遮一次原字符、空 mask、非法 mask。增加固定 seed 小字典，与朴素 `mb_substr` 枚举所有位置的结果比较，oracle 只在 tests。

```php
it('returns an allow recommendation for empty results', function () {
    $r = new ScanResult('正常', []);
    expect($r->count())->toBe(0)->and($r->matched())->toBeFalse()
        ->and($r->highestSeverity())->toBeNull()
        ->and($r->recommendedAction())->toBe(Action::Allow)
        ->and($r->shouldReview())->toBeFalse()->and($r->mask())->toBe('正常');
});
```

`composer check` Expected: 全通过。
- [ ] **Step 5: 精确暂存并提交。** `git commit -m "feat: 提供结构化匹配结果和原文遮罩"`。

## Task 5: Regex 规则匹配

**Files:** Create `src/Rules/RegexTarget.php`, `RegexRule.php`, `src/Matcher/RegexMatcher.php`; Test `tests/Unit/Matcher/RegexMatcherTest.php`。

**Interfaces:** `RegexTarget:string` Original/Normalized；readonly `RegexRule(string $id,string $pattern,string $category='default',Severity $severity=Severity::Medium,Action $action=Action::Flag,RegexTarget $target=RegexTarget::Original,array $metadata=[])`。`RegexMatcher(array $rules)` 实现 MatcherInterface，rules 为 list<RegexRule>；regex 结果 term=id、normalizedTerm=命中文本的归一化区间内容。

- [ ] **Step 1: 写成功、多命中和错误测试。**

```php
it('maps normalized regex offsets to original text', function () {
    $n = (new TextNormalizer())->normalize('微❤️信 微信');
    $d = (new DictionaryCompiler(new TextNormalizer()))->compile(new SensitiveDictionary('1', []));
    $matcher = new RegexMatcher([new RegexRule('wx', '/微信/u', target: RegexTarget::Normalized)]);
    $matches = $matcher->match($n, $d);
    expect(array_map(static fn (MatchResult $m) => $m->matchedText, $matches))->toBe(['微❤️信', '微信']);
});
it('rejects invalid patterns and ignores zero width results', function () {
    expect(fn () => new RegexRule('broken', '/[/u'))->toThrow(InvalidRuleException::class);
    $n = (new TextNormalizer())->normalize('中文');
    $d = (new DictionaryCompiler(new TextNormalizer()))->compile(new SensitiveDictionary('1', []));
    expect((new RegexMatcher([new RegexRule('zero', '/(?=中)/u')]))->match($n, $d))->toBe([]);
});
```

Run: `vendor/bin/pest tests/Unit/Matcher/RegexMatcherTest.php`；Expected: 缺类失败。

- [ ] **Step 2: 创建规则时校验，匹配时处理 PCRE offset。** V1 pattern delimiter 限定 `/`、`~`、`#`，结尾 modifiers 包含 u，非法或不支持格式直接 InvalidRuleException。以局部错误抑制 `@preg_match` 避免 warning 泄漏，必须立即检查 false 与 preg_last_error_msg；不装全局 error handler。按每个 target 一次构建 byte→codepoint 边界表，再转换 PREG_OFFSET_CAPTURE，不能每个命中 mb_strlen(前缀) 造成平方耗时。

```php
$count = @preg_match_all($rule->pattern, $subject, $found, PREG_OFFSET_CAPTURE);
if ($count === false) {
    throw new InvalidRuleException($rule->id.': '.preg_last_error_msg());
}
foreach ($found[0] as [$value, $byteOffset]) {
    if ($value === '') {
        continue;
    }
    $start = $byteToPoint[$byteOffset];
    $end = $byteToPoint[$byteOffset + strlen($value)];
    $span = $rule->target === RegexTarget::Normalized
        ? $text->span($start, $end) : new SourceSpan($start, $end);
    $normalizedTerm = $rule->target === RegexTarget::Normalized
        ? $value : $text->normalizedRangeForOriginal($span->start, $span->end);
    $matches[] = new MatchResult($rule->id, $normalizedTerm, $text->sliceOriginal($span),
        $rule->category, $rule->severity, $rule->action, $span->start, $span->end, 'regex', $rule->metadata);
}
```

- [ ] **Step 3: 增加运行错误与重叠回归。** 规则 `/(*NO_JIT)(*LIMIT_MATCH=10)(a+)+$/u` 对 `str_repeat('a',100).'!'` 必须抛 InvalidRuleException；规则不匹配正常返回 []。测试 `/中+/u` 的 original byte 映射、regex 与 AC 同范围保留两条、两条规则重叠均返回。不改变全局 pcre 配置来满足测试。
- [ ] **Step 4: `composer check` 通过，精确暂存并提交。** `git commit -m "feat: 增加 Unicode 正则规则匹配"`。

## Task 6: 局部白名单过滤

**Files:** Create `src/Rules/WhitelistMode.php`, `WhitelistRule.php`, `src/Matcher/WhitelistMatcher.php`; Test `tests/Unit/Matcher/WhitelistMatcherTest.php`。

**Interfaces:** `WhitelistMode:string` Exact/Phrase；readonly `WhitelistRule(string $text,WhitelistMode $mode=WhitelistMode::Phrase)`；`WhitelistMatcher(TextNormalizer $normalizer,array $rules)` 构造时用独立编译器编译白名单，`filter(NormalizedText $text,array $matches):array`。不实现 MatcherInterface，因为它消费并过滤结果。为白名单构造私有 SensitiveTerm(category:'whitelist',action:Allow)，复用 AC 不另造匹配引擎。

- [ ] **Step 1: 写局部包含测试并运行红灯。**

```php
it('only removes the match contained in a whitelisted phrase', function () {
    $normalizer = new TextNormalizer();
    $n = $normalizer->normalize('反博彩宣传，参与博彩');
    $d = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [new SensitiveTerm('博彩')]));
    $matches = (new AhoCorasickMatcher())->match($n, $d);
    $filter = new WhitelistMatcher($normalizer, [new WhitelistRule('反博彩宣传')]);
    $kept = $filter->filter($n, $matches);
    expect($kept)->toHaveCount(1)->and($kept[0]->start)->toBe(8);
});
```

Run: `vendor/bin/pest tests/Unit/Matcher/WhitelistMatcherTest.php`；Expected: 缺类失败。

- [ ] **Step 2: 实现 Exact/完整包含过滤。** Exact 比较 text.normalizedRangeForOriginal 与规范化白名单值；Phrase 用独立 AC 获得原文区间。按 start 排序短语区间，做 prefix-max end + 二分搜索，判断 candidate.start 之前的最大 end 是否覆盖 candidate.end，避免每命中遍历所有白名单。

```php
$left = 0;
$right = count($phraseStarts);
while ($left < $right) {
    $mid = intdiv($left + $right, 2);
    if ($phraseStarts[$mid] <= $match->start) {
        $left = $mid + 1;
    } else {
        $right = $mid;
    }
}
$contained = $left > 0 && $prefixMaxEnds[$left - 1] >= $match->end;
```

- [ ] **Step 3: 测 Exact、部分交叉、重叠白名单与无关结果。** 用 `博彩平台` 命中、`反博彩` 白名单验证不误删部分交叉；exact `博彩` 删除单词命中但保留 `博彩平台`；重复白名单不会重复结果；`反博❤️彩宣传` 按相同 normalizer 允许。`composer check` Expected: 全通过。
- [ ] **Step 4: 精确暂存并提交。** `git commit -m "feat: 增加局部精确和短语白名单"`。

## Task 7: Redis 字典协议和一致快照

**Files:** Create `src/Contracts/RedisClientInterface.php`, `DictionaryRepositoryInterface.php`, `src/Dictionary/DictionaryJsonCodec.php`, `RedisDictionaryRepository.php`, `tests/Helpers/FakeRedisClient.php`; Test `tests/Unit/Redis/RedisDictionaryRepositoryTest.php`; Create `docs/dictionary-protocol.md`。

**Interfaces:** RedisClientInterface 只提供所需命令，不暴露任意 command/mixed：

```php
interface RedisClientInterface
{
    public function get(string $key): ?string;
    /** @return array{0:?string,1:?string} version,payload */
    public function readSnapshot(string $versionKey, string $dictionaryKey): array;
    /** Returns new version, or null on compare-and-swap conflict. */
    public function compareAndSwap(string $versionKey, string $dictionaryKey, ?string $expectedVersion, string $payload): ?string;
}
interface DictionaryRepositoryInterface
{
    public function version(): string;
    public function load(): SensitiveDictionary;
}
```

`RedisDictionaryRepository(RedisClientInterface $redis,string $prefix='sensitive_text:',string $dictionaryKey='dictionary',string $versionKey='dictionary:version')`；额外具体 API `publish(array $terms,?string $expectedVersion):string` 做离线词库上传；冲突抛 DictionaryException。`DictionaryJsonCodec::encode(array $terms):string`、`decode(string $payload):array` → list<SensitiveTerm>。version 为不含前导零的非负十进制字符串，限制 19 位且不超过 9223372036854775807，不在 PHP int 或 Lua double 中做整体数值运算。

- [ ] **Step 1: FakeRedisClient 模拟事务边界并编写红灯测试。** Fake 用 array<string,string> 存值，get 返回 null 或 string；readSnapshot 一次返回两个字段；CAS expected 不同返回 null，否则更新 JSON/版本；测试辅助 `seed(string $version,string $payload)`、`fail:bool`、`getCalls:int` 用于注入故障。

```php
it('publishes and loads a versioned snapshot and rejects lost updates', function () {
    $redis = new FakeRedisClient();
    $repo = new RedisDictionaryRepository($redis);
    expect($repo->publish([new SensitiveTerm('赌博')], null))->toBe('1');
    $snapshot = $repo->load();
    expect($snapshot->version)->toBe('1')->and($snapshot->terms[0]->term)->toBe('赌博');
    expect($repo->publish([], '1'))->toBe('2');
    expect(fn () => $repo->publish([new SensitiveTerm('旧词')], '1'))->toThrow(DictionaryException::class);
    expect($repo->load()->terms)->toBe([]);
});
```

Run: `vendor/bin/pest tests/Unit/Redis/RedisDictionaryRepositoryTest.php`；Expected: 缺类失败。

- [ ] **Step 2: 实现严格 JSON schema。** `json_decode(...,true,512,JSON_THROW_ON_ERROR)` 返回值在 codec 边界收窄：root 为对象对应数组、schema===1、terms 为 list、每条 term/category 为非空 string、severity 为枚举 int、action 为枚举 string、enabled 为 bool、metadata 满足 Task 1 类型。未知字段拒绝以暴露拼写错误；不使用 `(string)$value` 蒙混非法类型。无效 JSON/version/payload 抛 DictionaryException；原始 JSON 不带 normalizedTerm，始终由当前配置重新编译。

```json
{"schema":1,"terms":[{"term":"赌博","category":"gambling","severity":3,"action":"block","enabled":true,"metadata":{"source":"manual"}}]}
```

- [ ] **Step 3: 写入文档中的固定原子协议，交给下一任务 adapters 执行。** 读取脚本：

```lua
return {redis.call('GET', KEYS[1]), redis.call('GET', KEYS[2])}
```

CAS 脚本（expectedVersion 为 null 时客户端编码为空字符串）：

```lua
local current = redis.call('GET', KEYS[1])
if (current or '') ~= ARGV[1] then return false end
local value = current or '0'
if not string.match(value, '^%d+$') or (#value > 1 and string.sub(value, 1, 1) == '0') then
  return redis.error_reply('Invalid dictionary version')
end
if #value > 19 or (#value == 19 and value >= '9223372036854775807') then
  return redis.error_reply('Dictionary version overflow')
end
local digits = {}
local carry = 1
for i = #value, 1, -1 do
  local digit = tonumber(string.sub(value, i, i)) + carry
  if digit == 10 then digit = 0; carry = 1 else carry = 0 end
  digits[i] = tostring(digit)
end
local nextVersion = (carry == 1 and '1' or '') .. table.concat(digits)
redis.call('MSET', KEYS[1], nextVersion, KEYS[2], ARGV[2])
return nextVersion
```

全部校验与版本运算在写入前完成，最后唯一写命令 MSET 一次替换两个 key，不能用 INCR+SET 假装失败可回滚。文档要求两个 key 专用于包、版本只通过本协议变更、不得给任一 key 单独设置 TTL、发布期间不得同时有绕过 CAS 的外部写者。Task 8 对配置不允许脚本的 Redis 明确报错；增加最大版本溢出测试，验证失败后两个 key 都保持原值。

- [ ] **Step 4: 增加读取途中竞争、缺 key、坏 schema、非枚举值、disabled 和自定义 prefix 的测试。** Fake 的 readSnapshot 返回固定 pair 后模拟下一发布；load 的 version 与 payload 始终来自 pair，不调用独立 GET 拼接。version 检查与下一 load 的版本可以不同，以 load 快照为准。`composer check` Expected: 全通过。
- [ ] **Step 5: 精确暂存并提交。** `git commit -m "feat: 定义 Redis 词库快照和原子发布协议"`。

## Task 8: PhpRedis 适配与真实 Redis 测试

**Files:** Create `src/Redis/PhpRedisClientAdapter.php`; Test `tests/Unit/Redis/ClientAdapterTest.php`, `tests/Integration/Redis/RedisDictionaryRepositoryTest.php`。

**Interfaces:** `PhpRedisClientAdapter(\Redis $client)` 实现 Task 7 interface。扩展返回类型只在这里收窄：GET false/null→null，字符串保留，其余抛 RedisUnavailableException；snapshot 必须两个元素；CAS false/null→冲突 null，成功为数字字符串。任何连接/命令失败包装 RedisUnavailableException 并保留 previous。`ext-redis` 是 Composer 强制依赖，缺扩展时安装即失败；不实现替代客户端或运行时降级。Redis Cluster 仍不支持。

- [ ] **Step 1: 写 adapter 单测与真实集成测试。** Unit 使用 PHPUnit/Pest 内置 mock 或可控 adapter stub，不实例化网络连接。真实测试只按环境开关跳过，启用后连接失败必须失败，不能再 skip。

```php
beforeEach(function () {
    if (getenv('SENSITIVE_TEXT_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Set SENSITIVE_TEXT_REDIS_TESTS=1 for Redis integration');
    }
});
it('round trips on real redis', function () {
    $client = new \Redis();
    $client->connect(
        getenv('SENSITIVE_TEXT_REDIS_HOST') ?: '127.0.0.1',
        (int) (getenv('SENSITIVE_TEXT_REDIS_PORT') ?: 6379),
        1.0,
    );
    $prefix = 'sensitive_text:test:'.bin2hex(random_bytes(8)).':';
    try {
        $repo = new RedisDictionaryRepository(new PhpRedisClientAdapter($client), $prefix);
        $v = $repo->publish([new SensitiveTerm('微信')], null);
        expect($repo->load()->version)->toBe($v)->and($repo->load()->terms[0]->term)->toBe('微信');
        expect(fn () => $repo->publish([], '0'))->toThrow(DictionaryException::class);
    } finally {
        $client->del([$prefix.'dictionary', $prefix.'dictionary:version']);
        $client->close();
    }
});
```

Run: `vendor/bin/pest tests/Unit/Redis/ClientAdapterTest.php`；Expected: 缺 adapter 失败；普通 composer test 的真实 Redis suite 可 skip。

- [ ] **Step 2: 实现 PhpRedis adapter。** 使用上一任务的两个 Lua 脚本；如 repository 与 adapter 需要共用脚本，添加 `src/Redis/DictionaryScripts.php`，只负责脚本字符串。

```php
// PhpRedis: keys precede arguments in its packed argument array.
$raw = $this->client->eval($script, [$versionKey, $dictionaryKey, $expectedVersion ?? '', $payload], 2);
```

PHPStan 使用 ext-redis 类型信息分析 adapter。Composer 在安装阶段验证扩展；代码不做 `extension_loaded()` 分支，不提供 fallback，也不把缺扩展转换成运行时配置错误。

- [ ] **Step 3: 启动/使用本地专用 Redis，实跑 phpredis 集成测试。**

```bash
SENSITIVE_TEXT_REDIS_TESTS=1 vendor/bin/pest tests/Integration/Redis
```

CI 必须安装 ext-redis；缺扩展时 Composer 安装失败，集成测试不得以缺扩展为由 skip。集成测试使用两个独立 phpredis 连接覆盖 CAS 冲突，并覆盖缺失 key、合法空库、自定义 prefix、socket 断开错误包装、Lua 被禁止的错误包装；禁止 FLUSHDB/FLUSHALL，只删除随机前缀的两个 key。

- [ ] **Step 4: `composer check` 全通过，精确暂存并提交。** `git commit -m "feat: 接入 phpredis 并验证快照协议"`。

## Task 9: 主服务、快照热替换和 Worker 安全

**Files:** Create `src/Contracts/ClockInterface.php`, `src/Support/SystemClock.php`, `src/SensitiveTextConfig.php`, `src/SensitiveText.php`, `src/Result/ScannerStats.php`, `tests/Helpers/FakeClock.php`, `FakeRepository.php`; Test `tests/Feature/SensitiveTextTest.php`, `WorkerLifecycleTest.php`。

**Interfaces:** ClockInterface `monotonic():float` 秒，`wallTime():DateTimeImmutable`；SystemClock 基于 hrtime(true)/1e9 和 UTC now。FakeClock `advance(float):void`。FakeRepository 有 `public SensitiveDictionary $snapshot`、`public bool $fail=false`、versionCalls/loadCalls 计数，构造接受 snapshot，接口返回对应字段；失败抛 RedisUnavailableException。

`SensitiveTextConfig` readonly 字段：`NormalizerConfig $normalizer=new NormalizerConfig()`、`string $redisUrl='tcp://127.0.0.1:6379'`、`string $redisPrefix='sensitive_text:'`、dictionaryKey/versionKey、`float $versionCheckInterval=5.0`、`float $redisTimeout=1.0`、`string $maskCharacter='*'`、`array $regexRules=[]`、`array $whitelistRules=[]`。不提供 Redis driver 选择；固定使用必需的 phpredis。拒绝负 interval/非正 timeout/空 key/两个 key 相同/无效 mask。不添加无法配置行为的 reload enum；自动刷新失败保留 last-known-good、手动刷新失败抛异常即固定 reload policy。

`SensitiveText::__construct(TextNormalizer $normalizer,DictionaryRepositoryInterface $repository,array $matchers,?DictionaryCompiler $compiler=null,?WhitelistMatcher $whitelist=null,?ClockInterface $clock=null,float $versionCheckInterval=5.0)`；matchers 为 list<MatcherInterface>，至少一个。`scan(string):ScanResult`、`reload():void`、`invalidate():void`、`stats():ScannerStats`。缺省 compiler 必须使用同一 normalizer。`ScannerStats` readonly：dictionaryVersion:?string、termCount:int、automatonNodeCount:int、lastCompileDuration:float、lastReloadAt:?DateTimeImmutable、estimatedMemoryBytes:int、versionLastCheckedAt:?DateTimeImmutable、lastReloadError:?string。只记录异常类与受控消息，不包含 Redis 密码和原文。

- [ ] **Step 1: 写 interval/reload/last-known-good 红灯测试。**

```php
it('reuses and replaces snapshots while retaining last known good on failure', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('赌博')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);
    expect($scanner->scan('赌博')->matched())->toBeTrue();
    $repo->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('微信')]);
    expect($scanner->scan('微信')->matched())->toBeFalse();
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()->and($scanner->stats()->dictionaryVersion)->toBe('2');
    $repo->fail = true;
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue();
    expect(fn () => $scanner->reload())->toThrow(RedisUnavailableException::class);
    expect($scanner->stats()->dictionaryVersion)->toBe('2');
});
```

Run: `vendor/bin/pest tests/Feature`；Expected: 缺主服务失败。

- [ ] **Step 2: 实现编译快照生命周期。** `private ?CompiledDictionary $compiled=null; private bool $refreshing=false; private ?float $lastChecked=null;`。refresh guard 在调用 repository 前设为 true；finally 清除。自动版本检查在 interval 到期后读 version，没变化只改检查时间；有变化 load→compile，完整成功后以一个赋值替换。比较与发布由一个 refresh guard 串行化，避免协程挂起期间旧任务覆盖新版本。

```php
private function replaceDictionary(): void
{
    $candidate = $this->compiler->compile($this->repository->load());
    $this->compiled = $candidate;
    $this->lastReloadAt = $this->clock->wallTime();
    $this->lastReloadError = null;
}
public function scan(string $text): ScanResult
{
    $dictionary = $this->dictionaryForScan();
    $normalized = $this->normalizer->normalize($text);
    $matches = [];
    foreach ($this->matchers as $matcher) {
        array_push($matches, ...$matcher->match($normalized, $dictionary));
    }
    return new ScanResult($text, $this->whitelist->filter($normalized, $matches));
}
```

`dictionaryForScan():CompiledDictionary` 是本类私有方法：按上述 guard/interval 策略获取快照；先 `lastChecked=monotonic()` 再 I/O；自动路径仅捕获 DictionaryException 及其子类，有旧快照记录错误继续，无旧快照抛。业务输入 NormalizationException、Regex 运行错误不能被该 catch 吞掉。返回后 matcher 始终使用方法局部的同一个 dictionary，后续 reload 不改变本次扫描的快照。

- [ ] **Step 3: 添加 Worker 状态、失败节流和原子替换测试。** 每轮 scan A/B/C/D 验证 matches/offset/text 不复用。FakeRepository 扩展可选 `?Closure $onLoad` 回调，在 load 内重入 scanner.scan：已有快照时返回旧结果、首次初始化时抛 DictionaryException。回调是同步测试 seam，不引入 Fiber。

```php
it('does not retain request-specific results', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    foreach (['微❤️信', '', '正常', '微信'] as $text) {
        $r = $scanner->scan($text);
        expect($r->matched())->toBe(in_array($text, ['微❤️信', '微信'], true));
    }
    expect($repo->loadCalls)->toBe(1);
});
```

再测试失败后 interval 内 100 次 scan 不新增 Redis GET、`invalidate()` 强制下次检查、无效新词库不替换、v1→v2 空库是有效更新、旧 ScanResult 在 reload 后仍不变、stats 初始为空且不触发 Redis I/O。

- [ ] **Step 4: `composer check` 通过，精确暂存并提交。** `git commit -m "feat: 实现词库热替换和常驻 Worker 安全扫描"`。

## Task 10: 普通 PHP 入口与同步 Batch

**Files:** Create `src/Support/DefaultScanner.php`, `src/Runtime/RuntimeEnvironment.php`, `SyncBatchExecutor.php`, `src/Contracts/BatchExecutorInterface.php`; Modify `src/SensitiveText.php`; Test `tests/Feature/SingletonTest.php`, `tests/Unit/Runtime/RuntimeEnvironmentTest.php`, `SyncBatchExecutorTest.php`。

**Interfaces:** `SensitiveText::instance():self`、`SensitiveText::fromConfig(SensitiveTextConfig $config):self`。`DefaultScanner::instance():SensitiveText` @internal，只有该类持有 static scanner。RuntimeEnvironment enum Cli/Fpm/Swoole/OpenSwoole/RoadRunner/FrankenPhp/Unknown；`detect(?string $sapi=null,array $signals=[]):self` 使用可传入事实列表用于测试，signals 为 array<string,bool>。BatchExecutorInterface `scan(iterable $texts):iterable`，PHPDoc `@param iterable<array-key,string>`、`@return iterable<array-key,ScanResult>`；SyncBatchExecutor(SensitiveText $scanner)。

- [ ] **Step 1: 写无网络初始化、singleton 独立性和 batch 测试。**

```php
it('keeps the default singleton separate from explicit instances', function () {
    expect(SensitiveText::instance())->toBe(SensitiveText::instance());
    $repo = new FakeRepository(new SensitiveDictionary('custom', [new SensitiveTerm('自定义')]));
    $custom = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    expect($custom)->not->toBe(SensitiveText::instance())
        ->and($custom->scan('自定义')->matched())->toBeTrue()
        ->and(SensitiveText::instance()->stats()->dictionaryVersion)->toBeNull();
});
it('preserves batch keys and laziness', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', []));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    $results = (new SyncBatchExecutor($scanner))->scan(['a' => '正常', 'b' => '']);
    expect($repo->loadCalls)->toBe(0);
    expect(array_keys(iterator_to_array($results)))->toBe(['a', 'b']);
});
```

Run: `vendor/bin/pest tests/Feature/SingletonTest.php tests/Unit/Runtime`；Expected: 缺入口/批处理失败。

- [ ] **Step 2: 实现工厂与入口。** DefaultScanner 内部 lazy `self::$scanner ??= SensitiveText::fromConfig(new SensitiveTextConfig())`；fromConfig 构建 normalizer、`PhpRedisClientAdapter`/repository、AC/Regex matchers、whitelist，再调用 public 构造。连接必须推迟到首次命令；在 `PhpRedisClientAdapter` 增加 `fromUrl(string,float):self` 与内部 lazy connection 配置，不能在 singleton 获取时建立网络。URL 解析检查 host/port/user/password/db/TLS scheme，并把 timeout 映射到 phpredis 的 connect/read timeout；不把 URL 写入异常。没有 Redis driver 选择或客户端 fallback。

```php
public function scan(iterable $texts): iterable
{
    foreach ($texts as $key => $text) {
        yield $key => $this->scanner->scan($text);
    }
}
```

- [ ] **Step 3: 实现运行时识别与测试。** 明确信号优先于 PHP_SAPI，优先顺序 FrankenPHP、RoadRunner、OpenSwoole、Swoole、FPM、CLI、Unknown；仅安装扩展不能证明正在对应 runtime。detect 的实际 signals 可由 `defined('FRANKENPHP')`、`getenv('RR_MODE')`、有效 coroutine context/宿主显式传入确定；无法可靠判断返回 Unknown 或 SAPI，文档说明。Laravel 的 Octane 配置判断留在 Task 11。

```php
expect(RuntimeEnvironment::detect('cli', ['roadrunner' => true]))->toBe(RuntimeEnvironment::RoadRunner);
expect(RuntimeEnvironment::detect('fpm-fcgi'))->toBe(RuntimeEnvironment::Fpm);
expect(RuntimeEnvironment::detect('cli'))->toBe(RuntimeEnvironment::Cli);
```

- [ ] **Step 4: `composer check`，补非法 URL、认证、database 和超时参数用例，提交。** `git commit -m "feat: 提供独立 PHP 入口和同步批处理"`。

## Task 11: Laravel 可选集成

**Files:** Create `src/Laravel/SensitiveTextServiceProvider.php`, `LaravelRedisAdapter.php`, `Facades/SensitiveText.php`, `config/sensitive-text.php`, `tests/Integration/Laravel/TestCase.php`, `ProviderTest.php`; Modify `composer.json`, `tests/Pest.php`, `.php-cs-fixer.php`, `phpstan.neon.dist`。

**Interfaces:** LaravelRedisAdapter 接受 `Illuminate\Redis\Connections\PhpRedisConnection`，按 Task 7 interface 映射 command/get/eval；lazy resolver 取得连接后也必须验证该具体类型，不支持 Laravel 的其他 Redis client。不能使用 facade 作为全局连接。Provider singleton 使用 closure 解析 config 与指定 `redis connection`；核心 SensitiveText 类型不依赖 Laravel。Facade accessor 返回核心类名。

- [ ] **Step 1: 核对真实 Laravel/Testbench 版本并配置测试。** Composer 在 PHP 8.2 求解 Laravel12/Testbench10/Pest3 通道；较新 PHP 求解 Laravel13/Testbench11/Pest4 通道。若不兼容，明确收窄 Laravel 声称支持的范围，不添加生产 Illuminate require。

```php
// tests/Integration/Laravel/TestCase.php
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\VergilLai\SensitiveText\Laravel\SensitiveTextServiceProvider::class];
    }
}
// tests/Pest.php
uses(\VergilLai\SensitiveText\Tests\Integration\Laravel\TestCase::class)->in('Integration/Laravel');
```

在 composer.extra.laravel.providers 添加 `VergilLai\\SensitiveText\\Laravel\\SensitiveTextServiceProvider`；suggest 只写 Laravel 集成所需的 Illuminate support/redis 版本范围。`ext-redis` 已是生产强制依赖，不放在 suggest。

- [ ] **Step 2: 写 provider 注册/无网络 singleton 测试并运行红灯。**

```php
it('registers a lazy singleton independently of the static entry', function () {
    $a = $this->app->make(\VergilLai\SensitiveText\SensitiveText::class);
    $b = $this->app->make(\VergilLai\SensitiveText\SensitiveText::class);
    expect($a)->toBe($b)->not->toBe(\VergilLai\SensitiveText\SensitiveText::instance())
        ->and($a->stats()->dictionaryVersion)->toBeNull();
});
```

Run: `vendor/bin/pest tests/Integration/Laravel`；Expected: 缺 provider 失败。

- [ ] **Step 3: 实现 provider/config/facade。** config 至少包含 redis.connection（default）、redis.prefix、dictionary.key/version_key、normalizer 七个字段、version_check_interval=5、mask_character='*'、regex_rules=[]、whitelist.rules=[]、reload.policy='keep_last_good'、batch.driver='sync'。不提供 redis.driver 或 redis.timeout 配置；宿主连接必须由 Laravel phpredis driver 创建，连接 timeout/read_timeout 由宿主 `config/database.php` 的 `database.redis` 连接配置管理，包不得修改共享连接。普通 PHP 的 `SensitiveTextConfig::redisTimeout` 保持不变。拒绝不支持的 policy/batch driver，不假装已有不同策略。配置里的规则用纯数组和 enum 的标量值，由 provider 转为 DTO，保证 config:cache 可用。

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../../config/sensitive-text.php', 'sensitive-text');
    $this->app->singleton(\VergilLai\SensitiveText\SensitiveText::class, function ($app) {
        return $this->buildScanner($app['config']->get('sensitive-text'));
    });
}
public function boot(): void
{
    $this->publishes([__DIR__.'/../../config/sensitive-text.php' => config_path('sensitive-text.php')], 'sensitive-text-config');
}
```

`buildScanner(array $config):SensitiveText` 私有，按 Task 10 同样构造对象。始终使用 LaravelRedisAdapter 的 lazy connection resolver，闭包仅捕获 Redis manager 和 connection name，不捕获 request/app snapshot；解析后必须是 `PhpRedisConnection`，否则抛 `InvalidConfigurationException`，不降级为其他客户端或包自建连接。不在 provider boot/init 注册 Octane tick/worker 事件；不保存 request、user 或 facade result。

`mask_character` 为结果调用的默认策略时，新增 `ScanResult` 第三构造参数 `string $defaultMask='*'`，`mask(?string $mask=null)` 使用显式参数优先；SensitiveText 构造末尾增 `string $maskCharacter='*'` 并将其传给 ScanResult，fromConfig 和 provider 同步。Task 4 所有原有调用仍兼容，增加配置 '#' 测试。

- [ ] **Step 4: 验证 config merge/publish/cache、facade、Redis adapter 和模拟 Octane。** 测试将连接替换为 FakeRedisClient 支持的适配 test double；Facade scan 返回与 app singleton 相同结果；连续请求模拟不得重新 load。provider 构造不取得真实连接。artisan `vendor:publish --tag=sensitive-text-config` 写到 Testbench 临时 app；config cache 后规则能重新构造。另用临时 Laravel app Composer path repository `composer require` 验证 **不手工注册 Provider** 的 package discovery；Testbench 显式 getPackageProviders 本身不能证明 auto discovery。

- [ ] **Step 5: 扩展 PHPStan/风格扫描至 config，运行 `composer check`，提交。** `git commit -m "feat: 提供 Laravel 自动发现和常驻服务适配"`。

## Task 12: 性能基准与内存测量

**Files:** Create `benchmarks/worker.php`, `benchmarks/run.php`, `docs/performance.md`; Test `tests/Unit/Runtime/BenchmarkOutputTest.php`; Modify `.php-cs-fixer.php`, `phpstan.neon.dist`。

**Interfaces:** `php benchmarks/worker.php <termCount> <textLength> <iterations>` 输出一行 JSON；`php benchmarks/run.php` 用独立 PHP 子进程逐个执行 4×3 组合，保存 JSONL。run 使用同步 `proc_open` argv 数组，不 fork worker 内对象、不并行扫描、不新增 benchmark 库。worker 调用实际 normalizer/compiler/scanner，对无匹配/稀疏/密集文本分别测量。

- [ ] **Step 1: 写 smoke 输出契约。** smoke 为 1000 词×100 字×3 次；需要字段 terms、textCodepoints、normalizationMs、compileMs、nodes、rawTermsBytes、compiledBytes、bytesPerNode、bytesPerTerm、warmScanP50Ms、warmScanP95Ms、coldScanMs、matchesPerSecond、reloadMs、peakMemoryBytes、php、icu。测试只验证非负有限数和规模字段，不能在 CI 对绝对毫秒做易抖阈值断言。

```php
it('defines finite per-node memory for a compiled dictionary', function () {
    $terms = array_map(static fn (int $i) => new SensitiveTerm('term'.$i), range(1, 1000));
    $d = (new DictionaryCompiler(new TextNormalizer()))->compile(new SensitiveDictionary('1', $terms));
    expect(count($d->transitions))->toBeGreaterThan(0)
        ->and(is_finite($d->estimatedMemoryBytes / count($d->transitions)))->toBeTrue();
});
```

- [ ] **Step 2: 实现可复现数据和计时。** 生成固定宽度词 `sprintf('term%06d',$i)` 并加入中文样本；mb_strlen 核对文本恰为 100/1000/10000 codepoints；无匹配文本用不在字典中的字符；密集文本允许展示大输出开销。每个 worker 先 `gc_collect_cycles()`，分别记录原始 terms、compiled 增量内存和 process peak，不能把 `memory_get_usage(true)` 差值当准确对象尺寸。

```php
$start = hrtime(true);
$compiled = $compiler->compile($dictionary);
$compileMs = (hrtime(true) - $start) / 1e6;
$samples = [];
$emitted = 0;
for ($i = 0; $i < $iterations; ++$i) {
    $start = hrtime(true);
    $result = $scanner->scan($text);
    $samples[] = (hrtime(true) - $start) / 1e6;
    $emitted += $result->count();
}
sort($samples);
$matchesPerSecond = array_sum($samples) > 0 ? $emitted / (array_sum($samples) / 1000) : 0.0;
```

冷扫描必须使用新 scanner；纯算法 benchmark 使用基准专属 DictionaryRepository 实现（定义在 worker 内、返回固定 snapshot）；真实 Redis cold/reload 另以 `--redis` 模式记录包含网络的指标，不能混称同一时间。run 汇总 host/PHP/ICU、迭代数、数据形态和 raw JSON 路径。

- [ ] **Step 3: 执行完整矩阵和 Unicode 病态输入。** 1000/10000/50000/100000 ×100/1000/10000；另测一串 10000 combining marks 与高 suffix overlap 词库，标出 normalization/mapping/结果分配成本。内存不足时如实报告失败点和峰值，不缩小到 1000 词后宣称完成 100k 验证。measure stats 的 estimatedMemoryBytes 明确为近似，不描述为独占内存精确值。
- [ ] **Step 4: 写 Fork 评估。** V1 sync 默认；未来只有 CLI/offline、每子进程新建连接、固定文本集 100/1000/10000 实测吞吐和总内存有收益才考虑 ForkBatchExecutor；HTTP/Octane 禁用。因为本版无 Fork executor，不伪造 Sync/Fork 对比数据，不推荐 spatie/fork 加速单文本。
- [ ] **Step 5: 质量检查和提交。** 将 benchmarks 加入 PHPStan/Fixer；`composer check`，`git commit -m "perf: 添加扫描编译和内存基准"`。

## Task 13: 文档、CI 与发布验收

**Files:** Create `README.md`, `LICENSE`, `CHANGELOG.md`, `.gitattributes`, `.github/workflows/ci.yml`, `docs/release-checklist.md`; Modify `composer.json`（description/license/keywords/discovery 最终核对）。

**Interfaces:** 最终用户只需 Composer require + 配置并发布 Redis 词库即可 scan。README 的所有 example 对应已定义 public API，不要求 import 内部默认 singleton 类。

- [ ] **Step 1: 写 README 的可执行 Quick Start。** 安装命令、Redis 初始化和扫描必须连续可运行，不能让用户以为包自带业务敏感词。

```php
use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\SensitiveText;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379, 1.0);
$repository = new RedisDictionaryRepository(new PhpRedisClientAdapter($redis));
// 仅首次创建；后续发布传入实际 expectedVersion，冲突后重新读取与合并。
$repository->publish([new SensitiveTerm('微信', 'contact', Severity::Medium, Action::Review)], null);
$result = SensitiveText::instance()->scan('请加我微❤️信联系');
echo $result->matches()[0]->matchedText;
echo $result->mask('*');
```

README 依次覆盖 Installation、Requirements、Quick Start、Architecture、Redis Setup、Dictionary Format、Normalization、Aho-Corasick、Regex Rules、Whitelist、Mask、Dictionary Reload、Laravel Usage、Octane Usage、Batch Scanning、Performance、Worker Safety、Error Handling、Testing、PHPStan、PHP-CS-Fixer、Benchmark。Requirements 明确 ext-redis 为强制依赖，缺失时 Composer 阻止安装，不提供 fallback；Redis Cluster 不支持。完整列明公开 API 和默认策略；Laravel app()/Facade/config 发布两种示例，并注明连接必须使用 Laravel phpredis driver；提及配置 prefix 与宿主 Redis prefix 不能重复叠加。

必须原样包括：`Fiber is not used in the core scan path.`；`Fork-based processing is intended for CLI/offline batch workloads only.`；`Compiled Automaton is reused inside long-running workers.`；`request-specific state is never stored on singleton services.`。说明 V1 只有同步 executor、周期轮询、invalidate 接口，无自建 Pub/Sub listener；说明 UTF-8 offsets、emoji mask 数量、非 semantic whitelist 和默认归一化对低误判的取舍。

- [ ] **Step 2: CI 分真实 PHP 通道验证。** 工作流目标矩阵 PHP 8.2/8.3/8.4/8.5；8.2 用 Pest3+Testbench10，较新通道选择可兼容组合，Laravel13 通道只在 solver 成功且测试过后声明。每个通道都必须安装 intl/mbstring/redis 扩展，不能忽略 ext-redis 平台要求；Redis service 健康就绪后置 `SENSITIVE_TEXT_REDIS_TESTS=1`。一条通道启用 PCOV/Xdebug 跑 85% coverage，其余不启用 coverage。加 8.2 `--prefer-lowest --prefer-stable` 通道检查最低依赖；不固定 platform 假装 PHP 8.2。

```yaml
steps:
  - uses: actions/checkout@v4
  - uses: shivammathur/setup-php@v2
    with:
      php-version: '${{ matrix.php }}'
      extensions: intl, mbstring, redis
      coverage: none
  - run: composer update --prefer-dist --no-interaction
  - run: composer validate --strict
  - run: composer check-platform-reqs
  - run: composer check
    env:
      SENSITIVE_TEXT_REDIS_TESTS: '1'
```

以上 steps 放入 matrix job 并补 `services.redis.image: redis:7`、端口 6379、redis-cli ping health check；coverage 独立 job 覆盖设置为 pcov 并运行 composer test:coverage；执行时按官方 action 元数据核对版本/固定 SHA，不为此升级业务依赖。PHPStan max 不允许测试目录被偷偷排除。

- [ ] **Step 3: 发布前外部消费 smoke。** `composer archive --format=zip --dir=/tmp/sensitive-text-release`；解包到临时目录，独立 Composer consumer 以 path repository 安装该包 `--no-dev`，运行真实 Redis 示例并确认安装集合没有 Illuminate/Pest/PHPStan。consumer 内必须执行下面的安装集合断言与平台检查：已安装包 metadata 的 `requires.ext-redis` 必须精确为 `*`，Predis 一旦存在就令 smoke 失败，`composer check-platform-reqs` 必须以 0 退出且机器可解析结果中 ext-redis status 为 success；不能只检查源包的 composer.json。对另一临时 Laravel consumer 验证 discovery 和 Facade。`.gitattributes` export-ignore `/tests`、`/benchmarks`、`/.github`、`/docs/superpowers`、开发工具配置；保留 README、LICENSE、config 和生产 src。archive 与可实际在 Packagist 下载安装不同，不能把本地 smoke 当作已经发布。

```bash
package_json=$(composer show vergil-lai/sensitive-text --format=json) || exit $?
printf '%s\n' "$package_json" | php -r '
$package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($package["requires"]["ext-redis"] ?? null) !== "*") {
    fwrite(STDERR, "Installed package must require ext-redis: *\n");
    exit(1);
}
'

installed_json=$(composer show --format=json) || exit $?
printf '%s\n' "$installed_json" | php -r '
$document = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$packages = $document["installed"] ?? null;
if (!is_array($packages)) {
    fwrite(STDERR, "Composer installed package list is missing\n");
    exit(1);
}
$names = array_map(
    static fn (array $package): ?string => $package["name"] ?? null,
    $packages,
);
if (in_array("predis/predis", $names, true)) {
    fwrite(STDERR, "Unexpected installed package: predis/predis\n");
    exit(1);
}
'

platform_json=$(composer check-platform-reqs --format=json)
platform_status=$?
printf '%s\n' "$platform_json"
if [ "$platform_status" -ne 0 ]; then
  exit "$platform_status"
fi
printf '%s\n' "$platform_json" | php -r '
$requirements = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$redis = array_values(array_filter(
    $requirements,
    static fn (array $requirement): bool => ($requirement["name"] ?? null) === "ext-redis",
));
if (count($redis) !== 1 || ($redis[0]["status"] ?? null) !== "success") {
    fwrite(STDERR, "ext-redis platform requirement did not succeed\n");
    exit(1);
}
'
```

执行时先以 `composer check-platform-reqs --help` 验证 `--format=json`；当前 Composer 2.9.5 已验证支持。若目标 Composer 不支持该 flag，稳定替代方案是：普通 `composer check-platform-reqs` 必须以 0 退出，再对 `composer show ext-redis --format=json` 用 PHP/JSON 解析确认 name 为 `ext-redis` 且 versions 非空；包 metadata 的 `requires.ext-redis === '*'` 断言仍必须独立执行。记录安装包 metadata、从 installed package names 得出的 Predis 缺席结果，以及 `ext-redis ... success`；任何 Composer 命令或 JSON schema 异常都使 smoke 失败。

- [ ] **Step 4: 执行最终验收并记录证据。**

```bash
composer validate --strict
composer check-platform-reqs
composer check
SENSITIVE_TEXT_REDIS_TESTS=1 composer test
composer test:coverage
php benchmarks/run.php
```

在安装 coverage driver 的环境执行 coverage；未有 PHP8.2/真实 Redis/Octane 宿主的项目必须逐项报告未验证。模拟 Worker 测试不等于 Swoole、RoadRunner、FrankenPHP 真机兼容验证；README 用“按常驻进程安全设计”描述未实测 runtime，不虚构全部认证。

- [ ] **Step 5: 写 CHANGELOG、发布清单、精确提交。** MIT 许可、Composer 包名、namespace、文档示例均核对。`git commit -m "docs: 完善安装文档和发布验证流程"`。发布清单区分已通过/未运行/失败；版本 tag、远端 push、Packagist 注册留待明确授权。

## 需求覆盖自检

| 原始需求章节 | 实施任务 |
| --- | --- |
| 1–6 包基础、架构、流水线 | 1、9、10、13 |
| 7–10 归一化、配置、Unicode/offset | 2、4 |
| 11–16 AC、编译、词模型、枚举 | 1、3、4 |
| 17–19 结果与遮罩 | 4、11（默认 mask 配置） |
| 20–23 Regex、白名单、Matcher 扩展 | 5、6 |
| 24–34 Redis、版本、polling、缓存取舍、fallback | 7、8、9；Pub/Sub 为 invalidate 接口而非 listener |
| 35–41 Singleton、Worker、原子替换 | 9、10 |
| 42–47 Fiber/Fork/runtime/batch | 10、12、13；V1 不实现 Fork |
| 48–55 Laravel、Provider、config、Facade、PHP API | 10、11、13 |
| 56–57 Stats、异常 | 1、9 |
| 58–70 Pest、Redis、Singleton、Worker、Testbench | 各任务测试，8/9/10/11 为集成门槛 |
| 71–78 PHPStan、CS、scripts、dependencies、coverage | 1、13 |
| 79–81 性能、Batch、内存 | 12；Fork 对比仅在实现 Fork 时触发 |
| 82–88 README、排除项、设计边界、PHPDoc | 1–13 的接口文档；13 汇总 |
| 89 开发顺序 | 依赖顺序保持，TDD 将每模块失败测试前置，末尾截断以前文性能/文档要求补齐 |

## 自审与交接

自审检查：所有需求有任务落点；五项 Review Focus 都有所属测试；公开 offset/enum/rule/Redis 接口在任务间一致；Task 11 对默认 mask 的兼容扩展有明确同步范围。没有把测试、benchmark 或 runtime 兼容性写成已经通过。

推荐 **Subagent-driven**：Unicode provenance、Redis 原子协议和常驻生命周期的缺陷容易被正常示例掩盖，逐任务独立审查比只在最后审查更适合这 13 个任务。Native 也可按同一计划逐项执行，最后独立整体验收。

依 writing-plans 技能的 Execution Handoff：先请用户审阅计划及上述设计约定、选择 Subagent-driven 或 Native；收到确认后才进入实施。此次停止点来自用户显式调用的 planning workflow，不是代码执行故障。

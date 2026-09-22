# 最终审查修复报告

## 状态

DONE_WITH_CONCERNS：`final-review.md` 中的 2 个 Important 和 2 个 Minor 已按冻结语义修复；focused 测试、完整 `composer check` 和 benchmark smoke 均通过。未修改发布状态、未推送。

## 修复范围

### I1：白名单密集命中的平方级原文反查

- `WhitelistMatcher` 在 Exact/Phrase 都为空时直接返回原命中列表。
- 仅有 Phrase 时完全跳过 `normalizedRangeForOriginal()`。
- 存在 Exact 时按原文区间缓存相同 span 的归一化结果。
- `NormalizedText::normalizedRangeForOriginal()` 改为对单调 `offsetMap` 做两次二分定位，只复制实际相交字符，复杂度由每次 `O(text)` 降为 `O(log text + result)`。
- 固定 `ﬃ` 的 Exact 语义：Exact `f` 不放行共享整个原文簇的两个 `f` 命中，Exact `ffi` 放行它们。

### I2：关闭 NFKC 后的完整字符簇映射

- `unicodeNfkc=false` 时簇内每个 code point 都映射到同一个完整 `SourceSpan(sourceIndex, clusterEnd)`；只跳过 NFKC 分解/重组。
- 新增 `a + U+0315` 的 offset map 和端到端 mask 回归，命中范围为 `[0,2)`，mask 为 `**`。

### M1：公开 DI 构造器的轮询间隔校验

- `SensitiveText` 与 `SensitiveTextConfig` 使用相同规则：拒绝负数、`NAN`、`INF` 和 `-INF`，异常信息保持一致。

### M2：文档边界

- README 将 Exact 明确定义为“命中原文区间的归一化文本等于 Exact 规则的归一化文本”。
- `docs/dictionary-protocol.md` 明确 V1 完全不支持 Redis Cluster，同 slot 也不支持。

## TDD 证据

RED：

- 1,000 与 4,000 code point 的窄区间重复查询倍率为 `7.962`，超过结构回归阈值 `2.5`。
- `unicodeNfkc=false` 时组合簇 offset map 实际为 `[[0,1],[1,2]]`，期望为 `[[0,2],[0,2]]`。
- 端到端组合符命中实际范围为 `[0,1)`，期望 `[0,2)`。
- DI 构造器没有对 `NAN` 抛出 `InvalidConfigurationException`。

GREEN：

- 相同倍率测试低于 `2.5`，不依赖绝对毫秒阈值。
- focused Matcher：`9 passed (19 assertions)`。
- focused Normalizer：`32 passed (48 assertions)`。
- focused Feature：`12 passed (185 assertions)`。

Exact 的 `ﬃ` 用例属于冻结现有语义的字符化测试；初始夹具把两个 `f` 命中误写为一个，修正为两个后用于保证优化不改变 Exact 行为。

## 完整验证

```text
rtk composer check
PHP-CS-Fixer 3.95.26: 0/74 files fixable
PHPStan 2.2.14 max: No errors
Pest: 209 passed, 5 Redis skipped (723 assertions)
```

第一次在受限沙盒内执行时，PHP-CS-Fixer 并行进程因不能监听 `tcp://127.0.0.1:0` 而报 `EPERM`；在沙盒外重跑同一完整命令后通过。这不是代码失败。

Benchmark smoke：

```text
php benchmarks/worker.php 1000 100 3
dense: 100 code points, 33 matches, P50 0.242 ms, P95 0.246 ms

php benchmarks/worker.php 1000 10000 1 --pathological
dense: 10,000 code points, 3,333 matches, 72.817 ms
combining marks: 10,000 input/mapping entries, 20.881 ms
suffix overlap: 64 terms, 1,000 code points, 61,984 matches, 315.720 ms
```

## 文件

- `src/Matcher/WhitelistMatcher.php`
- `src/Normalizer/NormalizedText.php`
- `src/Normalizer/TextNormalizer.php`
- `src/SensitiveText.php`
- `tests/Unit/Matcher/WhitelistMatcherTest.php`
- `tests/Unit/Normalizer/OffsetMappingTest.php`
- `tests/Feature/SensitiveTextTest.php`
- `README.md`
- `docs/dictionary-protocol.md`
- `.superpowers/sdd/2026-09-21-sensitive-text/final-fix-report.md`

## 疑虑与限制

- 本轮完整测试按仓库默认设置跳过了 5 个真实 Redis 集成测试；四项修复不涉及 Redis 命令路径。
- 本机没有执行 coverage，也没有重跑完整 4×3 benchmark 矩阵；本轮按要求执行普通 smoke 和 dense/pathological 定向 smoke。
- 运行环境为 PHP 8.5.4；PHP-CS-Fixer 提示项目最低 PHP 为 8.2，本轮没有重新执行 PHP 8.2 最低依赖矩阵。

# Sensitive Text 实施计划

## 目标

提供面向 PHP 8.2+ 的 Unicode 敏感文本过滤库。调用方用字符串数组创建不可变过滤器，然后判断、查找或掩码文本。

## 公共接口

- `new SensitiveText(words: list<string>, allowPhrases: list<string> = [])`
- `contains(string $text): bool`
- `find(string $text): list<MatchResult>`
- `mask(string $text, string $mask = '*'): string`
- `MatchResult` 只包含 `word`、`text`、`start`、`end`

`start`、`end` 是原文 Unicode 码点的半开区间。词库为空时构造合法，所有查询均返回无命中结果。

## 匹配行为

1. 构造时严格校验敏感词和允许短语为字符串列表。
2. 对两类字符串执行相同的 Unicode 归一化。
3. 按归一化结果去重并保留最先传入的原词。
4. 编译 Aho-Corasick 自动机并由实例独立持有。
5. 查找时将归一化命中映射回原文码点区间。
6. 敏感词范围被允许短语范围完整覆盖时过滤该命中。
7. 掩码时合并重叠或相邻范围，避免重复替换。

## 内部性能边界

- 词库编译只生成归一化字符串，不建立原文来源映射。
- 自动机直接使用 Unicode 字符作为转移键，并复用已编译实例。
- 无允许短语时，`contains()` 流式归一化并在首个命中处返回；有允许短语时保留完整映射和覆盖区间扫描。
- 来源映射使用两个 packed 整数列表，`SourceSpan` 只在真实命中时创建。
- ASCII 文本走等价的轻量归一化路径，Unicode 文本保留完整 grapheme 和 ICU 处理。

## Laravel

- 配置只包含 `words` 与 `allow_phrases` 两个字符串数组。
- Provider 注册一个 `SensitiveText` singleton。
- Facade 暴露 `contains()`、`find()` 和 `mask()`。

## 验证

- 单元测试覆盖词库校验、归一化、自动机、区间映射和允许短语。
- 功能测试覆盖三个公共方法、空词库、去重、实例隔离和掩码。
- Laravel 测试覆盖配置、singleton 和 Facade。
- 发布测试从 Composer 归档安装独立 PHP 与 Laravel 消费端。
- 最终执行 `composer validate --strict`、`composer check`、示例、基准 smoke、发布 smoke 和 `git diff --check`。

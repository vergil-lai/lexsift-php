# Changelog

## [Unreleased]

### Changed

- Rename package to `vergil-lai/lexsift-php` and namespace to `VergilLai\LexSift`.
- Replace `SensitiveText` with `Matcher`, matching the LexSift extension API.
- Return scan arrays with `term`, `text`, `start`, `end` and UTF-8 byte offsets.
- Merge overlapping and adjacent mask ranges and insert replacement once per range.
- Add six normalization options and atomic `replaceTerms()` / `replaceWhitelist()`.
- Accept associative dictionaries and report invalid inputs as `TypeError` / `ValueError`.
- Normalize across grapheme boundaries and apply whitelist containment in normalized text.
- Preserve Aho-Corasick matching, packed source mappings, ASCII fast path and benchmarks.

### Removed

- Laravel provider, facade, configuration, Testbench dependency and integration tests.

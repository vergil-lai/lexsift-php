# Changelog

All notable changes to this project will be documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Unicode-aware normalization with original-text code-point offset mapping.
- Aho-Corasick dictionary matching, regex rules, whitelists, masking, and scan statistics.
- Atomic versioned Redis dictionary publication through the required phpredis extension.
- Lazy reload, last-known-good snapshots, synchronous batch scanning, and Laravel package discovery.
- Reproducible benchmarks, quality checks, release smoke tests, and a PHP 8.2–8.5 CI matrix.

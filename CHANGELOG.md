# Changelog

All notable changes to `mage-zero/magento2-opensearch-observability` are documented in this file.

## [1.0.3] - 2026-02-19

- fix(apm): retry profiler driver initialization when Magento ObjectManager is not ready during early bootstrap.
- feat(apm): add optional legacy-compatible bootstrap config support via `app/etc/apm.php`.
- test: add unit coverage for bootstrap option parsing and deferred initialization behavior.
- docs: clarify profiler activation requirements and bootstrap configuration options.

## [1.0.2] - 2026-02-16

- fix(php): adjust profiler method signature compatibility for PHP 8.4.

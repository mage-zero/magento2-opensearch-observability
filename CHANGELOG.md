# Changelog

All notable changes to `mage-zero/magento2-opensearch-observability` are documented in this file.

## [2.0.0] - 2026-02-19

- feat(apm): replace profiler-driver OTLP emitter with Datadog ddtrace hook registration at Magento bootstrap.
- feat(apm): add configurable custom span coverage for event dispatch, layout rendering, plugin lookup, and DI object manager calls.
- breaking(apm): remove profiler-driver classes, DB profiler integration, and `app/etc/apm.php` bootstrap fallback.
- docs: rewrite APM docs/admin wording for Datadog tracer runtime model.
- test: replace profiler/OTLP unit coverage with Datadog hook registrar coverage.

## [1.1.0] - 2026-02-19

- feat(tracing): switch profiler transport from Elastic APM intake to OpenTelemetry OTLP trace payloads.
- feat(tracing): keep existing `MZ_APM_*` config surface while targeting OTLP collector endpoints (for deploy continuity).
- refactor: remove `nipwaayoni/elastic-apm-php-agent` and `php-http/guzzle7-adapter` runtime dependencies.
- test: update profiler unit tests for OTLP payload emission.
- docs: update admin/readme wording to OTLP tracing semantics.

## [1.0.3] - 2026-02-19

- fix(apm): retry profiler driver initialization when Magento ObjectManager is not ready during early bootstrap.
- feat(apm): add optional legacy-compatible bootstrap config support via `app/etc/apm.php`.
- test: add unit coverage for bootstrap option parsing and deferred initialization behavior.
- docs: clarify profiler activation requirements and bootstrap configuration options.

## [1.0.2] - 2026-02-16

- fix(php): adjust profiler method signature compatibility for PHP 8.4.

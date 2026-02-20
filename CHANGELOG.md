# Changelog

All notable changes to `mage-zero/magento2-opensearch-observability` are documented in this file.

## [Unreleased]

- feat(apm): enrich custom span metadata with request method/host/path/uri/url for easier filtering and trace triage in OpenSearch Dashboards.
- refactor(apm): capture request context from front-controller request objects instead of parsing superglobals in the hook registrar.
- docs: add Trace Analytics default filter guidance (`trace.group.name = "magento.request"`) and request URL field references.
- test: add unit coverage for request metadata propagation in `DatadogHookRegistrar`.

## [2.0.2] - 2026-02-20

- fix(apm): retry Datadog hook registration later in request lifecycle instead of locking after an early disabled read.
- fix(apm): add front-controller registration hook so custom span registration still occurs when bootstrap-time config is not yet available.
- feat(apm): allow `MZ_APM_*` / `DD_*` environment overrides for early custom span enablement, service name, environment, and sample rate.
- test: add unit coverage for retry-after-early-disabled registration and front-controller tracing plugin behavior.

## [2.0.1] - 2026-02-20

- chore(release): bump module version to `2.0.1` after `2.0.0` Datadog cutover merge.
- ci: run full Magento/PHP matrix validation from a fresh post-merge release branch.

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

# MageZero Magento 2 OpenSearch Observability

`mage-zero/magento2-opensearch-observability` is an observability module for Magento 2.

It provides:
- Datadog (`ddtrace`) userland hook registration for Magento-specific spans.
- Configurable custom spans for:
  - event dispatch,
  - layout rendering,
  - plugin lookup (optional), and
  - DI object manager calls (optional).
- Optional Magento log streaming as structured JSON via:
  - `stderr` (collector-managed), or
  - direct HTTP push to OpenSearch/Elasticsearch.
- Admin configuration under `Stores > Configuration > MageZero > Observability Settings`.

Both feature switches are disabled by default.

## Compatibility

- Magento: `2.4.4` through `2.4.8` (including patch releases).
- PHP syntax compatibility: `7.4+` and `8.0+`.
- CI runtime matrix for Magento integration/unit tests: PHP versions supported by each Magento target.

## Log Transport Choice

You can choose between two log transports:

- `stderr` (recommended default):
  - Best when a platform collector (Filebeat/Fluent Bit/Vector) already ships logs.
  - Keeps delivery concerns outside Magento request execution.
- `direct`:
  - Sends each selected record to a configured OpenSearch/Elasticsearch endpoint.
  - Uses fail-open behavior and short timeouts so application flow is not blocked by transport failures.

## Installation

1. Require the module:

```bash
composer require mage-zero/magento2-opensearch-observability
```

2. Enable and upgrade:

```bash
bin/magento module:enable MageZero_OpensearchObservability
bin/magento setup:upgrade
bin/magento cache:flush
```

## Admin Configuration

Go to `Stores > Configuration > MageZero > Observability Settings`.

### Feature Switches

- `Enable Datadog Custom Spans` (default: `No`)
- `Enable Log Streaming` (default: `No`)

### Shared/Operational Settings

- Service name override
- Environment
- Transaction sample rate
- Event/layout/plugin/DI custom span toggles
- Log transport (`stderr` or `direct`)
- Log stream minimum level
- Direct endpoint URL/index/auth/timeout/TLS settings

## Enabling Trace Emission

Tracing now uses `dd-trace-php` as the runtime tracer. This module only registers Magento-specific custom spans.

### 1. Install and enable ddtrace extension

Install Datadog tracer for the target PHP runtime (package or PECL, depending on your build process) and ensure the extension is loaded in PHP-FPM/CLI workers that execute Magento.

### 2. Configure tracer runtime environment

Set Datadog tracer variables in your runtime:

- `DD_TRACE_ENABLED=1`
- `DD_TRACE_AGENT_URL=http://<collector-or-agent>:8126`
- `DD_SERVICE=<service-name>`
- `DD_ENV=<environment>`
- `DD_TRACE_SAMPLE_RATE=<0..1>`

### 3. Enable module custom spans

In Magento admin (`Stores > Configuration > MageZero > Observability Settings`):

- Enable `Datadog Custom Spans`
- Toggle event/layout/plugin/DI spans as needed

For early bootstrap registration, the module also supports environment overrides:

- `MZ_APM_ENABLED`
- `MZ_APM_SPAN_EVENTS_ENABLED`
- `MZ_APM_SPAN_LAYOUT_ENABLED`
- `MZ_APM_SPAN_PLUGINS_ENABLED`
- `MZ_APM_SPAN_DI_ENABLED`

When set, these env values take precedence and are applied before database-backed Magento config is fully loaded.

No Magento profiler driver bootstrap is required.

## OpenSearch Dashboards Trace View Tips

For cleaner Magento waterfall views, save a query in Trace Analytics:

- `trace.group.name = "magento.request"`

This filters out generic request groups and keeps Magento request traces front-and-center.

The module also adds request context metadata to custom spans. In OpenSearch these fields are visible as:

- `span.attributes.magento@request@method`
- `span.attributes.magento@request@host`
- `span.attributes.magento@request@path`
- `span.attributes.magento@request@uri`
- `span.attributes.magento@request@url`

Tip: add `span.attributes.url@full`, `span.attributes.http@request@method`, and `span.attributes.http@response@status_code` as trace detail fields alongside the Magento request fields above.

## Log Streaming Output Format

When enabled, selected Magento log handlers are mirrored as one JSON line per record, for example:

```json
{
  "@timestamp": "2026-02-16T20:00:00+00:00",
  "message": "Order failed",
  "log.level": "error",
  "magento.log_file": "exception.log",
  "magento.channel": "main",
  "context": {"order_id": 10},
  "extra": {}
}
```

For `stderr` transport, this is intended for collector ingestion from container stdout/stderr or PHP-FPM stderr output paths.

For `direct` transport, records are POSTed to:

`{base_url}/{index}/_doc`

Auth options:
- API key (`Authorization: ApiKey ...`) or
- Basic auth (username/password) when API key is not set.

## Testing and CI

The repository includes:
- Unit tests.
- Integration tests.
- Static analysis and style checks (coding standard, PHPStan, PHPMD).
- Syntax compatibility checks for PHP `7.4` through `8.3`.

See `.github/workflows/ci.yml` for the matrix and job definitions.

## Credits

This module builds on Magento observability patterns and Datadog tracer userland hook capabilities.

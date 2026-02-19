# MageZero Magento 2 OpenSearch Observability

`mage-zero/magento2-opensearch-observability` is an observability module for Magento 2.

It provides:
- Elastic APM transaction/span emission for Magento request profiling.
- Optional DB query spans (when Magento DB profiler is enabled).
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

- `Enable APM Integration` (default: `No`)
- `Enable Log Streaming` (default: `No`)

### Shared/Operational Settings

- APM server URL
- Service name override
- Environment
- Secret token (encrypted in config storage)
- Transaction sample rate
- Stack trace limit
- Timeout
- DB query span support
- Log transport (`stderr` or `direct`)
- Log stream minimum level
- Direct endpoint URL/index/auth/timeout/TLS settings

## Enabling APM Tracing

APM integration is implemented as a Magento profiler driver.

Profiler activation is required. If profiler is not enabled, no request traces are emitted.

### 1. Configure APM options

You can configure APM options in either location:

- Magento config (`Stores > Configuration > MageZero > Observability Settings`)
- Optional bootstrap file `app/etc/apm.php` (legacy-compatible, loaded very early)

Example `app/etc/apm.php`:

```php
<?php
return [
    'serverUrl' => 'http://apm-server:8200',
    'enabled' => true,
    'transactionSampleRate' => 1.0,
    'serviceName' => 'magento',
    'hostname' => 'app-node',
    'environment' => 'production',
    'stackTraceLimit' => 1000,
    'timeout' => 10,
];
```

When both sources are present and the module APM switch is enabled, module config values are used; `app/etc/apm.php` remains an early-boot fallback.

### 2. Enable profiler driver

Enable the profiler driver with:

```bash
bin/magento dev:profiler:enable '{"drivers":[{"type":"MageZero\\OpensearchObservability\\Profiler\\Driver"}]}'
```

This writes `var/profiler.flag` and activates the driver for web requests.

To disable:

```bash
bin/magento dev:profiler:disable
```

## Optional DB Query Spans

1. Enable `Enable DB Query Span Support` in admin config.
2. Configure Magento DB profiler class in `app/etc/env.php`:

```php
'db' => [
    'connection' => [
        'default' => [
            // ...
            'profiler' => [
                'class' => '\\MageZero\\OpensearchObservability\\Profiler\\Db',
                'enabled' => true,
            ],
        ],
    ],
],
```

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

This module is inspired by:
- `cmtickle/elastic-apm-magento` (initial Magento + Elastic APM integration approach)
  - https://github.com/cmtickle/elastic-apm-magento

Also builds on ideas from the Magento profiling ecosystem and Elastic APM PHP agent usage patterns.

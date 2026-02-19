<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Apm;

use Throwable;

class BootstrapOptionsReader
{
    private const DEFAULT_ENVIRONMENT = 'production';
    private const DEFAULT_SAMPLE_RATE = 1.0;
    private const DEFAULT_STACK_TRACE_LIMIT = 1000;
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function read(array $server = [], ?string $filePath = null): array
    {
        $path = $filePath !== null ? trim($filePath) : $this->resolveDefaultPath();
        if ($path === '' || !is_file($path)) {
            return [];
        }

        try {
            $raw = include $path;
        } catch (Throwable $exception) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        return $this->normalize($raw, $server);
    }

    private function resolveDefaultPath(): string
    {
        if (!defined('BP')) {
            return '';
        }

        return BP . '/app/etc/apm.php';
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array $server): array
    {
        $serviceName = $this->sanitizeServiceName((string)($raw['serviceName'] ?? ''));
        if ($serviceName === '') {
            $serviceName = $this->sanitizeServiceName((string)($server['HTTP_HOST'] ?? ''));
        }
        if ($serviceName === '') {
            $serviceName = 'magento';
        }

        $hostname = trim((string)($raw['hostname'] ?? ''));
        if ($hostname === '') {
            $hostname = trim((string)($server['HOSTNAME'] ?? ''));
        }
        if ($hostname === '') {
            $hostname = trim((string)gethostname());
        }
        if ($hostname === '') {
            $hostname = 'unknown-host';
        }

        $sampleRate = $this->normalizeSampleRate($raw['transactionSampleRate'] ?? self::DEFAULT_SAMPLE_RATE);
        $stackTraceLimit = $this->normalizePositiveInt(
            $raw['stackTraceLimit'] ?? self::DEFAULT_STACK_TRACE_LIMIT,
            self::DEFAULT_STACK_TRACE_LIMIT
        );
        $timeout = $this->normalizePositiveInt(
            $raw['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS,
            self::DEFAULT_TIMEOUT_SECONDS
        );
        $environment = trim((string)($raw['environment'] ?? self::DEFAULT_ENVIRONMENT));
        if ($environment === '') {
            $environment = self::DEFAULT_ENVIRONMENT;
        }

        $options = [
            'enabled' => $this->normalizeBoolean($raw['enabled'] ?? true, true),
            'serverUrl' => trim((string)($raw['serverUrl'] ?? '')),
            'serviceName' => $serviceName,
            'hostname' => $hostname,
            'environment' => $environment,
            'transactionSampleRate' => $sampleRate,
            'stackTraceLimit' => $stackTraceLimit,
            'timeout' => $timeout,
            'frameworkName' => trim((string)($raw['frameworkName'] ?? 'magento2')) ?: 'magento2',
        ];

        $secretToken = trim((string)($raw['secretToken'] ?? ''));
        if ($secretToken !== '') {
            $options['secretToken'] = $secretToken;
        }

        $frameworkVersion = trim((string)($raw['frameworkVersion'] ?? ''));
        if ($frameworkVersion !== '') {
            $options['frameworkVersion'] = $frameworkVersion;
        }

        $serviceVersion = trim((string)($raw['serviceVersion'] ?? ''));
        if ($serviceVersion !== '') {
            $options['serviceVersion'] = $serviceVersion;
        }

        return $options;
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolean($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private function normalizeSampleRate($value): float
    {
        $sampleRate = (float)$value;
        if ($sampleRate < 0.0) {
            return 0.0;
        }
        if ($sampleRate > 1.0) {
            return 1.0;
        }

        return $sampleRate;
    }

    /**
     * @param mixed $value
     */
    private function normalizePositiveInt($value, int $default): int
    {
        $normalized = (int)$value;
        if ($normalized < 1) {
            return $default;
        }

        return $normalized;
    }

    private function sanitizeServiceName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = (string)preg_replace('/[^A-Za-z0-9_-]/', '-', $value);
        $value = (string)preg_replace('/-+/', '-', $value);

        return trim($value, '-');
    }
}

<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const ENV_MZ_APM_ENABLED = 'MZ_APM_ENABLED';
    private const ENV_MZ_APM_SERVICE_NAME = 'MZ_APM_SERVICE_NAME';
    private const ENV_MZ_APM_ENVIRONMENT = 'MZ_APM_ENVIRONMENT';
    private const ENV_MZ_APM_SAMPLE_RATE = 'MZ_APM_SAMPLE_RATE';
    private const ENV_MZ_APM_SPAN_EVENTS_ENABLED = 'MZ_APM_SPAN_EVENTS_ENABLED';
    private const ENV_MZ_APM_SPAN_LAYOUT_ENABLED = 'MZ_APM_SPAN_LAYOUT_ENABLED';
    private const ENV_MZ_APM_SPAN_PLUGINS_ENABLED = 'MZ_APM_SPAN_PLUGINS_ENABLED';
    private const ENV_MZ_APM_SPAN_DI_ENABLED = 'MZ_APM_SPAN_DI_ENABLED';
    private const ENV_DD_TRACE_ENABLED = 'DD_TRACE_ENABLED';
    private const ENV_DD_SERVICE = 'DD_SERVICE';
    private const ENV_DD_ENV = 'DD_ENV';
    private const ENV_DD_TRACE_SAMPLE_RATE = 'DD_TRACE_SAMPLE_RATE';

    public const XML_PATH_APM_ENABLED = 'magezero/observability/apm_enabled';
    public const XML_PATH_APM_SERVICE_NAME = 'magezero/observability/apm_service_name';
    public const XML_PATH_APM_ENVIRONMENT = 'magezero/observability/apm_environment';
    public const XML_PATH_APM_TRANSACTION_SAMPLE_RATE = 'magezero/observability/apm_transaction_sample_rate';
    public const XML_PATH_APM_SPAN_EVENTS_ENABLED = 'magezero/observability/apm_span_events_enabled';
    public const XML_PATH_APM_SPAN_LAYOUT_ENABLED = 'magezero/observability/apm_span_layout_enabled';
    public const XML_PATH_APM_SPAN_PLUGINS_ENABLED = 'magezero/observability/apm_span_plugins_enabled';
    public const XML_PATH_APM_SPAN_DI_ENABLED = 'magezero/observability/apm_span_di_enabled';

    public const XML_PATH_LOG_STREAM_ENABLED = 'magezero/observability/log_stream_enabled';
    public const XML_PATH_LOG_STREAM_MIN_LEVEL = 'magezero/observability/log_stream_min_level';
    public const XML_PATH_LOG_STREAM_TRANSPORT = 'magezero/observability/log_stream_transport';
    public const XML_PATH_LOG_STREAM_DIRECT_URL = 'magezero/observability/log_stream_direct_url';
    public const XML_PATH_LOG_STREAM_DIRECT_INDEX = 'magezero/observability/log_stream_direct_index';
    public const XML_PATH_LOG_STREAM_DIRECT_API_KEY = 'magezero/observability/log_stream_direct_api_key';
    public const XML_PATH_LOG_STREAM_DIRECT_USERNAME = 'magezero/observability/log_stream_direct_username';
    public const XML_PATH_LOG_STREAM_DIRECT_PASSWORD = 'magezero/observability/log_stream_direct_password';
    public const XML_PATH_LOG_STREAM_DIRECT_TIMEOUT_MS = 'magezero/observability/log_stream_direct_timeout_ms';
    public const XML_PATH_LOG_STREAM_DIRECT_VERIFY_TLS = 'magezero/observability/log_stream_direct_verify_tls';

    private const DEFAULT_APM_ENVIRONMENT = 'production';
    private const DEFAULT_SAMPLE_RATE = 1.0;
    private const DEFAULT_LOG_MIN_LEVEL = 'warning';
    private const DEFAULT_LOG_STREAM_TRANSPORT = 'stderr';
    private const DEFAULT_LOG_STREAM_DIRECT_INDEX = 'magento-observability-logs';
    private const DEFAULT_LOG_STREAM_DIRECT_TIMEOUT_MS = 500;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function isApmEnabled(): bool
    {
        $envValue = $this->getEnvFlag(self::ENV_MZ_APM_ENABLED);
        if ($envValue !== null) {
            return $envValue;
        }

        $ddTraceEnabled = $this->getEnvFlag(self::ENV_DD_TRACE_ENABLED);
        if ($ddTraceEnabled !== null) {
            return $ddTraceEnabled;
        }

        return $this->isFlag(self::XML_PATH_APM_ENABLED);
    }

    public function getApmEnvironment(): string
    {
        $envValue = $this->getEnvValue(self::ENV_MZ_APM_ENVIRONMENT) ?? $this->getEnvValue(self::ENV_DD_ENV);
        if ($envValue !== null) {
            return $envValue;
        }

        $environment = trim($this->getString(self::XML_PATH_APM_ENVIRONMENT, self::DEFAULT_APM_ENVIRONMENT));

        return $environment !== '' ? $environment : self::DEFAULT_APM_ENVIRONMENT;
    }

    public function getTransactionSampleRate(): float
    {
        $sampleRateValue = $this->getEnvValue(self::ENV_MZ_APM_SAMPLE_RATE)
            ?? $this->getEnvValue(self::ENV_DD_TRACE_SAMPLE_RATE)
            ?? $this->getString(self::XML_PATH_APM_TRANSACTION_SAMPLE_RATE, (string)self::DEFAULT_SAMPLE_RATE);

        $value = (float)$sampleRateValue;

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    public function isApmSpanEventsEnabled(): bool
    {
        $envValue = $this->getEnvFlag(self::ENV_MZ_APM_SPAN_EVENTS_ENABLED);
        if ($envValue !== null) {
            return $envValue;
        }

        return $this->isFlag(self::XML_PATH_APM_SPAN_EVENTS_ENABLED);
    }

    public function isApmSpanLayoutEnabled(): bool
    {
        $envValue = $this->getEnvFlag(self::ENV_MZ_APM_SPAN_LAYOUT_ENABLED);
        if ($envValue !== null) {
            return $envValue;
        }

        return $this->isFlag(self::XML_PATH_APM_SPAN_LAYOUT_ENABLED);
    }

    public function isApmSpanPluginsEnabled(): bool
    {
        $envValue = $this->getEnvFlag(self::ENV_MZ_APM_SPAN_PLUGINS_ENABLED);
        if ($envValue !== null) {
            return $envValue;
        }

        return $this->isFlag(self::XML_PATH_APM_SPAN_PLUGINS_ENABLED);
    }

    public function isApmSpanDiEnabled(): bool
    {
        $envValue = $this->getEnvFlag(self::ENV_MZ_APM_SPAN_DI_ENABLED);
        if ($envValue !== null) {
            return $envValue;
        }

        return $this->isFlag(self::XML_PATH_APM_SPAN_DI_ENABLED);
    }

    public function isLogStreamingEnabled(): bool
    {
        return $this->isFlag(self::XML_PATH_LOG_STREAM_ENABLED);
    }

    public function getLogStreamMinLevel(): string
    {
        $level = strtolower(trim($this->getString(self::XML_PATH_LOG_STREAM_MIN_LEVEL, self::DEFAULT_LOG_MIN_LEVEL)));
        $allowed = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        if (!in_array($level, $allowed, true)) {
            return self::DEFAULT_LOG_MIN_LEVEL;
        }

        return $level;
    }

    public function getLogStreamTransport(): string
    {
        $transport = strtolower(trim($this->getString(self::XML_PATH_LOG_STREAM_TRANSPORT, self::DEFAULT_LOG_STREAM_TRANSPORT)));

        if (!in_array($transport, ['stderr', 'direct'], true)) {
            return self::DEFAULT_LOG_STREAM_TRANSPORT;
        }

        return $transport;
    }

    public function isDirectLogStreamEnabled(): bool
    {
        return $this->getLogStreamTransport() === 'direct';
    }

    public function getLogStreamDirectUrl(): string
    {
        return rtrim(trim($this->getString(self::XML_PATH_LOG_STREAM_DIRECT_URL)), '/');
    }

    public function getLogStreamDirectIndex(): string
    {
        $index = strtolower(trim($this->getString(self::XML_PATH_LOG_STREAM_DIRECT_INDEX, self::DEFAULT_LOG_STREAM_DIRECT_INDEX)));
        if ($index === '') {
            return self::DEFAULT_LOG_STREAM_DIRECT_INDEX;
        }

        $index = (string)preg_replace('/[^a-z0-9._-]/', '-', $index);
        $index = trim((string)preg_replace('/-+/', '-', $index), '-');

        return $index !== '' ? $index : self::DEFAULT_LOG_STREAM_DIRECT_INDEX;
    }

    public function getLogStreamDirectApiKey(): string
    {
        return $this->getDecryptedString(self::XML_PATH_LOG_STREAM_DIRECT_API_KEY);
    }

    public function getLogStreamDirectUsername(): string
    {
        return trim($this->getString(self::XML_PATH_LOG_STREAM_DIRECT_USERNAME));
    }

    public function getLogStreamDirectPassword(): string
    {
        return $this->getDecryptedString(self::XML_PATH_LOG_STREAM_DIRECT_PASSWORD);
    }

    public function getLogStreamDirectTimeoutMs(): int
    {
        $value = (int)$this->getString(
            self::XML_PATH_LOG_STREAM_DIRECT_TIMEOUT_MS,
            (string)self::DEFAULT_LOG_STREAM_DIRECT_TIMEOUT_MS
        );

        if ($value < 100) {
            return 100;
        }

        if ($value > 30000) {
            return 30000;
        }

        return $value;
    }

    public function shouldVerifyLogStreamDirectTls(): bool
    {
        $value = strtolower(trim($this->getString(self::XML_PATH_LOG_STREAM_DIRECT_VERIFY_TLS, '1')));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    /**
     * Resolve service name for APM. Configured value wins, then HTTP_HOST fallback.
     *
     * @param array<string, string> $server
     */
    public function getResolvedServiceName(array $server = []): string
    {
        $envServiceName = $this->sanitizeServiceName(
            (string)($this->getEnvValue(self::ENV_MZ_APM_SERVICE_NAME) ?? $this->getEnvValue(self::ENV_DD_SERVICE) ?? '')
        );
        if ($envServiceName !== '') {
            return $envServiceName;
        }

        $configured = $this->sanitizeServiceName($this->getString(self::XML_PATH_APM_SERVICE_NAME));
        if ($configured !== '') {
            return $configured;
        }

        $httpHost = isset($server['HTTP_HOST']) ? (string)$server['HTTP_HOST'] : '';
        $fallback = $this->sanitizeServiceName($httpHost);

        return $fallback !== '' ? $fallback : 'magento';
    }

    /**
     * Resolve runtime hostname for APM metadata.
     *
     * @param array<string, string> $server
     */
    public function getResolvedHostname(array $server = []): string
    {
        $hostname = isset($server['HOSTNAME']) ? trim((string)$server['HOSTNAME']) : '';
        if ($hostname === '') {
            $hostname = trim((string)gethostname());
        }

        return $hostname !== '' ? $hostname : 'unknown-host';
    }

    private function isFlag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path);
    }

    private function getEnvFlag(string $name): ?bool
    {
        $value = $this->getEnvValue($name);
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function getEnvValue(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return null;
        }

        $trimmed = trim((string)$value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function getString(string $path, string $default = ''): string
    {
        $value = $this->scopeConfig->getValue($path);
        if ($value === null || $value === '') {
            return $default;
        }

        return (string)$value;
    }

    private function getDecryptedString(string $path): string
    {
        $value = trim($this->getString($path));
        if ($value === '') {
            return '';
        }

        try {
            $decrypted = trim((string)$this->encryptor->decrypt($value));
            return $decrypted !== '' ? $decrypted : $value;
        } catch (\Throwable $exception) {
            return $value;
        }
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

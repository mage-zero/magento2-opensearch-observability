<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_PATH_APM_ENABLED = 'magezero/observability/apm_enabled';
    public const XML_PATH_APM_SERVER_URL = 'magezero/observability/apm_server_url';
    public const XML_PATH_APM_SERVICE_NAME = 'magezero/observability/apm_service_name';
    public const XML_PATH_APM_ENVIRONMENT = 'magezero/observability/apm_environment';
    public const XML_PATH_APM_SECRET_TOKEN = 'magezero/observability/apm_secret_token';
    public const XML_PATH_APM_TRANSACTION_SAMPLE_RATE = 'magezero/observability/apm_transaction_sample_rate';
    public const XML_PATH_APM_STACK_TRACE_LIMIT = 'magezero/observability/apm_stack_trace_limit';
    public const XML_PATH_APM_TIMEOUT = 'magezero/observability/apm_timeout';
    public const XML_PATH_APM_DB_PROFILER_ENABLED = 'magezero/observability/apm_db_profiler_enabled';

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
    private const DEFAULT_STACK_TRACE_LIMIT = 1000;
    private const DEFAULT_TIMEOUT_SECONDS = 10;
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
        return $this->isFlag(self::XML_PATH_APM_ENABLED);
    }

    public function getApmServerUrl(): string
    {
        return trim($this->getString(self::XML_PATH_APM_SERVER_URL));
    }

    public function getApmEnvironment(): string
    {
        $environment = trim($this->getString(self::XML_PATH_APM_ENVIRONMENT, self::DEFAULT_APM_ENVIRONMENT));

        return $environment !== '' ? $environment : self::DEFAULT_APM_ENVIRONMENT;
    }

    public function getApmSecretToken(): string
    {
        return $this->getDecryptedString(self::XML_PATH_APM_SECRET_TOKEN);
    }

    public function getTransactionSampleRate(): float
    {
        $value = (float)$this->getString(self::XML_PATH_APM_TRANSACTION_SAMPLE_RATE, (string)self::DEFAULT_SAMPLE_RATE);

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    public function getStackTraceLimit(): int
    {
        $value = (int)$this->getString(self::XML_PATH_APM_STACK_TRACE_LIMIT, (string)self::DEFAULT_STACK_TRACE_LIMIT);

        return $value > 0 ? $value : self::DEFAULT_STACK_TRACE_LIMIT;
    }

    public function getTimeoutSeconds(): int
    {
        $value = (int)$this->getString(self::XML_PATH_APM_TIMEOUT, (string)self::DEFAULT_TIMEOUT_SECONDS);

        return $value > 0 ? $value : self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function isDbProfilerEnabled(): bool
    {
        return $this->isFlag(self::XML_PATH_APM_DB_PROFILER_ENABLED);
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

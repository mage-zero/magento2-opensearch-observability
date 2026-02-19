<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Profiler;

use MageZero\OpensearchObservability\Model\Apm\AgentOptionsBuilder;
use MageZero\OpensearchObservability\Model\Apm\BootstrapOptionsReader;
use MageZero\OpensearchObservability\Model\Config as ModuleConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Profiler\DriverInterface;
use Throwable;

class Driver implements DriverInterface
{
    private const INIT_STATE_PENDING = 'pending';
    private const INIT_STATE_READY = 'ready';
    private const INIT_STATE_DISABLED = 'disabled';

    private const OTEL_KIND_INTERNAL = 1;
    private const OTEL_KIND_SERVER = 2;
    private const OTEL_KIND_CLIENT = 3;

    private const OTEL_STATUS_OK = 1;
    private const OTEL_STATUS_ERROR = 2;

    /**
     * @var array<string, mixed>
     */
    private $driverConfig;

    /**
     * @var array<string, mixed>
     */
    private $activeOptions;

    /**
     * @var array<string, mixed>|null
     */
    private $transaction;

    /**
     * @var array<int, array<string, mixed>>
     */
    private $callStack;

    /**
     * @var array<int, array<string, mixed>>
     */
    private $finishedSpans;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var string
     */
    private $initState;

    /**
     * @var bool
     */
    private $shutdownRegistered;

    /**
     * @var bool
     */
    private $sent;

    /**
     * @var BootstrapOptionsReader
     */
    private $bootstrapOptionsReader;

    /**
     * @var string
     */
    private $traceId;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?BootstrapOptionsReader $bootstrapOptionsReader = null)
    {
        $this->driverConfig = $config;
        $this->activeOptions = [];
        $this->transaction = null;
        $this->callStack = [];
        $this->finishedSpans = [];
        $this->enabled = false;
        $this->initState = self::INIT_STATE_PENDING;
        $this->shutdownRegistered = false;
        $this->sent = false;
        $this->bootstrapOptionsReader = $bootstrapOptionsReader ?: new BootstrapOptionsReader();
        $this->traceId = '';

        $this->attemptInitialize();
    }

    public function init(): void
    {
        $this->attemptInitialize();
    }

    /**
     * @param mixed $timerId
     * @param array<string, mixed>|null $tags
     */
    public function start($timerId, ?array $tags = null): void
    {
        $this->attemptInitialize();

        if (!$this->enabled || $this->transaction === null) {
            return;
        }

        try {
            $event = $this->createSpan((string)$timerId, $tags ?: []);
            $this->callStack[] = $event;
        } catch (Throwable $exception) {
            // No-op. Observability should never break core request execution.
        }
    }

    /**
     * @param mixed $timerId
     */
    public function stop($timerId): void
    {
        $this->attemptInitialize();

        if (!$this->enabled || $this->transaction === null) {
            return;
        }

        $event = array_pop($this->callStack);
        if (!is_array($event)) {
            return;
        }

        try {
            $event['endTimeNs'] = $this->nowNs();
            $this->finishedSpans[] = $event;
        } catch (Throwable $exception) {
            // No-op. Observability should never break core request execution.
        }
    }

    /**
     * @param mixed $timerId
     */
    public function clear($timerId = null): void
    {
        if ($timerId !== null) {
            $timerId = (string)$timerId;
        }

        $this->callStack = [];
        $this->finishedSpans = [];
        $this->transaction = null;
    }

    public function send(): void
    {
        $this->attemptInitialize();

        if (!$this->enabled || $this->transaction === null || $this->sent) {
            return;
        }

        try {
            while (!empty($this->callStack)) {
                $event = array_pop($this->callStack);
                if (!is_array($event)) {
                    continue;
                }
                $event['endTimeNs'] = $this->nowNs();
                $this->finishedSpans[] = $event;
            }

            $statusCode = http_response_code();
            $httpStatusCode = $statusCode ? (int)$statusCode : 200;
            $this->transaction['endTimeNs'] = $this->nowNs();
            $this->transaction['statusCode'] = $httpStatusCode >= 500 ? self::OTEL_STATUS_ERROR : self::OTEL_STATUS_OK;
            $this->transaction['attributes']['http.status_code'] = $httpStatusCode;

            $payload = $this->buildOtlpPayload();
            $this->emitPayload($payload, $this->activeOptions);
            $this->sent = true;
        } catch (Throwable $exception) {
            // No-op. Observability should never break core request execution.
        }
    }

    private function attemptInitialize(): void
    {
        if ($this->initState === self::INIT_STATE_READY || $this->initState === self::INIT_STATE_DISABLED) {
            return;
        }

        $options = $this->readBootstrapOptions();
        $objectManager = $this->getObjectManagerInstance();
        $objectManagerReady = $objectManager instanceof ObjectManagerInterface;

        if ($objectManagerReady) {
            $moduleOptions = $this->buildOptionsFromModuleConfig($objectManager);
            if (!empty($moduleOptions)) {
                $options = array_merge($options, $moduleOptions);
            }
        }

        $this->applyDriverOverrides($options);

        if (!$this->canInitializeWithOptions($options)) {
            $this->initState = $objectManagerReady ? self::INIT_STATE_DISABLED : self::INIT_STATE_PENDING;
            return;
        }

        $sampleRate = $this->normalizeSampleRate($options['transactionSampleRate'] ?? 1.0);
        if ($sampleRate <= 0.0 || !$this->shouldSample($sampleRate)) {
            $this->activeOptions = $options;
            $this->enabled = false;
            $this->initState = self::INIT_STATE_READY;
            return;
        }

        try {
            $this->activeOptions = $options;
            $this->traceId = $this->generateTraceId();
            $this->transaction = $this->createTransactionSpan();
            $this->enabled = true;
            $this->initState = self::INIT_STATE_READY;
            $this->registerShutdownHandler();
        } catch (Throwable $exception) {
            $this->enabled = false;
            $this->transaction = null;
            $this->initState = $objectManagerReady ? self::INIT_STATE_DISABLED : self::INIT_STATE_PENDING;
        }
    }

    protected function registerShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        register_shutdown_function([$this, 'send']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readBootstrapOptions(): array
    {
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        return $this->bootstrapOptionsReader->read($_SERVER);
    }

    protected function getObjectManagerInstance(): ?ObjectManagerInterface
    {
        try {
            return ObjectManager::getInstance();
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildOptionsFromModuleConfig(ObjectManagerInterface $objectManager): array
    {
        try {
            /** @var ModuleConfig $moduleConfig */
            $moduleConfig = $objectManager->get(ModuleConfig::class);
            if (!$moduleConfig->isApmEnabled()) {
                return [];
            }

            /** @var AgentOptionsBuilder $optionsBuilder */
            $optionsBuilder = $objectManager->get(AgentOptionsBuilder::class);
            // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
            return $optionsBuilder->build($_SERVER);
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    protected function emitPayload(array $payload, array $options): void
    {
        $serverUrl = trim((string)($options['serverUrl'] ?? ''));
        if ($serverUrl === '') {
            return;
        }

        $timeoutSeconds = (int)($options['timeout'] ?? 10);
        if ($timeoutSeconds < 1) {
            $timeoutSeconds = 10;
        }

        $json = json_encode($payload);
        if (!is_string($json) || $json === '') {
            return;
        }

        $headers = [
            'Content-Type: application/json',
        ];

        $secretToken = trim((string)($options['secretToken'] ?? ''));
        if ($secretToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $secretToken;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($serverUrl);
            if ($ch === false) {
                return;
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, min($timeoutSeconds, 3)));
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $streamHeaders = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $streamHeaders,
                'content' => $json,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        @file_get_contents($serverUrl, false, $context);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function canInitializeWithOptions(array $options): bool
    {
        if (!$this->toBoolean($options['enabled'] ?? true)) {
            return false;
        }

        return trim((string)($options['serverUrl'] ?? '')) !== '';
    }

    /**
     * @param mixed $value
     */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    private function createSpan(string $timerId, array $tags): array
    {
        $shortTimerId = $this->shortenTimerId($timerId);
        $parent = count($this->callStack) > 0
            ? $this->callStack[count($this->callStack) - 1]
            : $this->transaction;

        $kind = self::OTEL_KIND_INTERNAL;
        $attributes = [
            'magezero.profiler.timer_id' => $timerId,
        ];

        foreach ($tags as $key => $value) {
            $attributes['magezero.tag.' . (string)$key] = $value;
        }

        if (strpos($shortTimerId, 'DB_QUERY') !== false) {
            $kind = self::OTEL_KIND_CLIENT;
            $attributes['db.system'] = 'mysql';
            if (isset($tags['statement'])) {
                $attributes['db.statement'] = (string)$tags['statement'];
            }
            if (isset($tags['operation'])) {
                $attributes['db.operation'] = (string)$tags['operation'];
            }
            if (isset($tags['host'])) {
                $attributes['server.address'] = (string)$tags['host'];
            }
        }

        return [
            'traceId' => $this->traceId,
            'spanId' => $this->generateSpanId(),
            'parentSpanId' => is_array($parent) ? (string)($parent['spanId'] ?? '') : '',
            'name' => $shortTimerId,
            'kind' => $kind,
            'startTimeNs' => $this->nowNs(),
            'endTimeNs' => null,
            'attributes' => $attributes,
            'statusCode' => self::OTEL_STATUS_OK,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createTransactionSpan(): array
    {
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';

        $attributes = [
            'http.method' => $method,
            'url.path' => $uri,
            'magezero.profiler.driver' => 'MageZero\\OpensearchObservability\\Profiler\\Driver',
            'magezero.profiler.sample_rate' => $this->normalizeSampleRate($this->activeOptions['transactionSampleRate'] ?? 1.0),
        ];

        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        if (isset($_SERVER['HTTP_HOST']) && (string)$_SERVER['HTTP_HOST'] !== '') {
            // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
            $attributes['server.address'] = (string)$_SERVER['HTTP_HOST'];
        }

        return [
            'traceId' => $this->traceId,
            'spanId' => $this->generateSpanId(),
            'parentSpanId' => '',
            'name' => $this->resolveTransactionName(),
            'kind' => self::OTEL_KIND_SERVER,
            'startTimeNs' => $this->nowNs(),
            'endTimeNs' => null,
            'attributes' => $attributes,
            'statusCode' => self::OTEL_STATUS_OK,
        ];
    }

    private function shortenTimerId(string $timerId): string
    {
        $parts = explode('>', $timerId);

        return (string)array_pop($parts);
    }

    private function resolveTransactionName(): string
    {
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';

        return trim($method . ' ' . $uri);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyDriverOverrides(array &$options): void
    {
        $supportedKeys = [
            'enabled',
            'serverUrl',
            'serviceName',
            'hostname',
            'environment',
            'secretToken',
            'transactionSampleRate',
            'stackTraceLimit',
            'timeout',
            'frameworkName',
            'frameworkVersion',
            'serviceVersion',
        ];

        foreach ($supportedKeys as $key) {
            if (!array_key_exists($key, $this->driverConfig)) {
                continue;
            }

            $options[$key] = $this->driverConfig[$key];
        }
    }

    private function nowNs(): int
    {
        return (int)floor(microtime(true) * 1000000000);
    }

    private function generateTraceId(): string
    {
        return $this->randomHex(16);
    }

    private function generateSpanId(): string
    {
        return $this->randomHex(8);
    }

    private function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable $exception) {
            $hex = '';
            while (strlen($hex) < ($bytes * 2)) {
                $hex .= dechex(mt_rand(0, PHP_INT_MAX));
            }
            return substr($hex, 0, $bytes * 2);
        }
    }

    private function normalizeSampleRate($value): float
    {
        $rate = (float)$value;
        if ($rate < 0.0) {
            return 0.0;
        }
        if ($rate > 1.0) {
            return 1.0;
        }

        return $rate;
    }

    private function shouldSample(float $sampleRate): bool
    {
        if ($sampleRate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOtlpPayload(): array
    {
        if ($this->transaction === null) {
            return ['resourceSpans' => []];
        }

        $spans = array_merge([$this->transaction], $this->finishedSpans);
        usort($spans, function (array $left, array $right): int {
            return ((int)$left['startTimeNs']) <=> ((int)$right['startTimeNs']);
        });

        $otelSpans = [];
        foreach ($spans as $span) {
            $otelSpans[] = $this->convertSpanToOtlp($span);
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->buildResourceAttributes(),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'magezero.opensearch-observability',
                                'version' => (string)($this->activeOptions['frameworkVersion'] ?? ''),
                            ],
                            'spans' => $otelSpans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildResourceAttributes(): array
    {
        $attributes = [
            ['key' => 'service.name', 'value' => $this->encodeAttributeValue((string)($this->activeOptions['serviceName'] ?? 'magento'))],
            ['key' => 'deployment.environment', 'value' => $this->encodeAttributeValue((string)($this->activeOptions['environment'] ?? 'production'))],
            ['key' => 'host.name', 'value' => $this->encodeAttributeValue((string)($this->activeOptions['hostname'] ?? 'unknown-host'))],
            ['key' => 'telemetry.sdk.name', 'value' => $this->encodeAttributeValue('magezero-profiler')],
            ['key' => 'telemetry.sdk.language', 'value' => $this->encodeAttributeValue('php')],
        ];

        if (isset($this->activeOptions['serviceVersion']) && trim((string)$this->activeOptions['serviceVersion']) !== '') {
            $attributes[] = [
                'key' => 'service.version',
                'value' => $this->encodeAttributeValue((string)$this->activeOptions['serviceVersion']),
            ];
        }

        if (isset($this->activeOptions['frameworkName']) && trim((string)$this->activeOptions['frameworkName']) !== '') {
            $attributes[] = [
                'key' => 'framework.name',
                'value' => $this->encodeAttributeValue((string)$this->activeOptions['frameworkName']),
            ];
        }

        if (isset($this->activeOptions['frameworkVersion']) && trim((string)$this->activeOptions['frameworkVersion']) !== '') {
            $attributes[] = [
                'key' => 'framework.version',
                'value' => $this->encodeAttributeValue((string)$this->activeOptions['frameworkVersion']),
            ];
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $span
     * @return array<string, mixed>
     */
    private function convertSpanToOtlp(array $span): array
    {
        $payload = [
            'traceId' => (string)$span['traceId'],
            'spanId' => (string)$span['spanId'],
            'name' => (string)$span['name'],
            'kind' => (int)$span['kind'],
            'startTimeUnixNano' => (string)((int)$span['startTimeNs']),
            'endTimeUnixNano' => (string)((int)$span['endTimeNs']),
            'attributes' => $this->convertAttributesToOtlp((array)$span['attributes']),
            'status' => [
                'code' => (int)$span['statusCode'],
            ],
        ];

        $parentSpanId = (string)($span['parentSpanId'] ?? '');
        if ($parentSpanId !== '') {
            $payload['parentSpanId'] = $parentSpanId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<int, array<string, mixed>>
     */
    private function convertAttributesToOtlp(array $attributes): array
    {
        $converted = [];
        foreach ($attributes as $key => $value) {
            $converted[] = [
                'key' => (string)$key,
                'value' => $this->encodeAttributeValue($value),
            ];
        }

        return $converted;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function encodeAttributeValue($value): array
    {
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }

        if (is_int($value)) {
            return ['intValue' => (string)$value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_array($value)) {
            $json = json_encode($value);
            return ['stringValue' => is_string($json) ? $json : ''];
        }

        if ($value === null) {
            return ['stringValue' => ''];
        }

        return ['stringValue' => (string)$value];
    }
}

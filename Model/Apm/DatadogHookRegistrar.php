<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Apm;

use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Event\ConfigInterface as EventConfigInterface;
use Throwable;

class DatadogHookRegistrar
{
    private const TRACE_METHOD_FUNCTION = 'DDTrace\\trace_method';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var EventConfigInterface
     */
    private $eventConfig;

    /**
     * @var string
     */
    private $magentoVersion;

    /**
     * @var bool
     */
    private $registered = false;

    /**
     * @var array<string, string>|null
     */
    private $requestMeta;

    public function __construct(
        Config $config,
        EventConfigInterface $eventConfig,
        ProductMetadataInterface $productMetadata
    ) {
        $this->config = $config;
        $this->eventConfig = $eventConfig;
        $this->magentoVersion = (string)$productMetadata->getVersion();
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        if (!$this->isTraceMethodAvailable() || !$this->config->isApmEnabled()) {
            return;
        }

        $this->applyRuntimeDatadogConfig();

        if ($this->config->isApmSpanEventsEnabled()) {
            $this->registerEventDispatchSpan();
        }

        if ($this->config->isApmSpanLayoutEnabled()) {
            $this->registerLayoutRenderSpan();
        }

        if ($this->config->isApmSpanPluginsEnabled()) {
            $this->registerPluginLookupSpan();
        }

        if ($this->config->isApmSpanDiEnabled()) {
            $this->registerDiSpans();
        }

        $this->registered = true;
    }

    protected function isTraceMethodAvailable(): bool
    {
        return function_exists(self::TRACE_METHOD_FUNCTION);
    }

    protected function registerMethodHook(string $className, string $methodName, callable $hook): bool
    {
        try {
            $traceMethod = self::TRACE_METHOD_FUNCTION;
            // `trace_method` is provided by ddtrace extension; fail-open if registration fails.
            return (bool)$traceMethod($className, $methodName, $hook);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function applyRuntimeDatadogConfig(): void
    {
        $this->applyIniSetting('datadog.trace.enabled', '1');
        $this->applyIniSetting('datadog.trace.sample_rate', (string)$this->config->getTransactionSampleRate());

        $serviceName = $this->config->getResolvedServiceName();
        if ($serviceName !== '') {
            $this->applyIniSetting('datadog.service', $serviceName);
        }

        $environment = $this->config->getApmEnvironment();
        if ($environment !== '') {
            $this->applyIniSetting('datadog.env', $environment);
        }
    }

    private function applyIniSetting(string $name, string $value): void
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            ini_set($name, $value);
        } catch (Throwable $exception) {
            // No-op. Observability must be fail-open.
        }
    }

    private function registerEventDispatchSpan(): void
    {
        $self = $this;
        $this->registerMethodHook(
            'Magento\\Framework\\Event\\Manager',
            'dispatch',
            static function () use ($self): void {
                $parameters = func_get_args();
                $span = $parameters[0] ?? null;
                $methodArguments = isset($parameters[1]) && is_array($parameters[1]) ? $parameters[1] : [];
                $eventName = isset($methodArguments[0]) ? trim((string)$methodArguments[0]) : '';
                $payload = isset($methodArguments[1]) && is_array($methodArguments[1]) ? $methodArguments[1] : [];

                $self->decorateSpan($span, 'magento.event.dispatch', [
                    'magento.event.name' => $eventName !== '' ? $eventName : 'unknown',
                    'magento.event.observer_count' => (string)$self->resolveObserverCount($eventName),
                    'magento.event.payload_keys' => (string)count($payload),
                ]);
            }
        );
    }

    private function registerLayoutRenderSpan(): void
    {
        $self = $this;
        $this->registerMethodHook(
            'Magento\\Framework\\View\\Layout',
            'renderElement',
            static function () use ($self): void {
                $parameters = func_get_args();
                $span = $parameters[0] ?? null;
                $methodArguments = isset($parameters[1]) && is_array($parameters[1]) ? $parameters[1] : [];
                $elementName = isset($methodArguments[0]) ? trim((string)$methodArguments[0]) : '';
                $useCache = isset($methodArguments[1]) ? (bool)$methodArguments[1] : true;

                $self->decorateSpan($span, 'magento.layout.render', [
                    'magento.layout.element' => $elementName !== '' ? $elementName : 'unknown',
                    'magento.layout.use_cache' => $useCache ? '1' : '0',
                ]);
            }
        );
    }

    private function registerPluginLookupSpan(): void
    {
        $self = $this;
        $this->registerMethodHook(
            'Magento\\Framework\\Interception\\PluginList\\PluginList',
            'getNext',
            static function () use ($self): void {
                $parameters = func_get_args();
                $span = $parameters[0] ?? null;
                $methodArguments = isset($parameters[1]) && is_array($parameters[1]) ? $parameters[1] : [];
                $returnValue = $parameters[2] ?? null;

                $subjectType = isset($methodArguments[0]) ? trim((string)$methodArguments[0]) : '';
                $methodName = isset($methodArguments[1]) ? trim((string)$methodArguments[1]) : '';
                $pluginCount = is_array($returnValue) ? count($returnValue) : 0;

                $self->decorateSpan($span, 'magento.interception.plugin_lookup', [
                    'magento.plugin.subject_type' => $subjectType !== '' ? $subjectType : 'unknown',
                    'magento.plugin.method' => $methodName !== '' ? $methodName : 'unknown',
                    'magento.plugin.count' => (string)$pluginCount,
                ]);
            }
        );
    }

    private function registerDiSpans(): void
    {
        $this->registerDiCreateSpan();
        $this->registerDiGetSpan();
    }

    private function registerDiCreateSpan(): void
    {
        $self = $this;
        $this->registerMethodHook(
            'Magento\\Framework\\ObjectManager\\ObjectManager',
            'create',
            static function () use ($self): void {
                $parameters = func_get_args();
                $span = $parameters[0] ?? null;
                $methodArguments = isset($parameters[1]) && is_array($parameters[1]) ? $parameters[1] : [];

                $type = isset($methodArguments[0]) ? trim((string)$methodArguments[0]) : '';
                $arguments = isset($methodArguments[1]) && is_array($methodArguments[1]) ? $methodArguments[1] : [];

                $self->decorateSpan($span, 'magento.di.create', [
                    'magento.di.type' => $type !== '' ? $type : 'unknown',
                    'magento.di.argument_count' => (string)count($arguments),
                ]);
            }
        );
    }

    private function registerDiGetSpan(): void
    {
        $self = $this;
        $this->registerMethodHook(
            'Magento\\Framework\\ObjectManager\\ObjectManager',
            'get',
            static function () use ($self): void {
                $parameters = func_get_args();
                $span = $parameters[0] ?? null;
                $methodArguments = isset($parameters[1]) && is_array($parameters[1]) ? $parameters[1] : [];
                $type = isset($methodArguments[0]) ? trim((string)$methodArguments[0]) : '';

                $self->decorateSpan($span, 'magento.di.get', [
                    'magento.di.type' => $type !== '' ? $type : 'unknown',
                ]);
            }
        );
    }

    /**
     * @param mixed $span
     * @param array<string, string> $meta
     */
    protected function decorateSpan($span, string $name, array $meta): void
    {
        if (!is_object($span)) {
            return;
        }

        try {
            $this->assignSpanIdentity($span, $name);
            $span->meta = $this->buildSpanMeta($span, $meta);
        } catch (Throwable $exception) {
            // No-op. Observability must be fail-open.
        }
    }

    /**
     * @param object $span
     */
    private function assignSpanIdentity($span, string $name): void
    {
        $serviceName = $this->config->getResolvedServiceName();
        $fields = [
            'name' => $name,
            'resource' => $name,
            'type' => 'custom',
        ];
        if ($serviceName !== '') {
            $fields['service'] = $serviceName;
        }

        foreach ($fields as $field => $value) {
            if (!property_exists($span, $field)) {
                continue;
            }

            $span->{$field} = $value;
        }
    }

    /**
     * @param object $span
     * @param array<string, string> $meta
     * @return array<string, string>
     */
    private function buildSpanMeta($span, array $meta): array
    {
        $spanMeta = [];
        if (isset($span->meta) && is_array($span->meta)) {
            $spanMeta = $span->meta;
        }

        $baseMeta = [
            'component' => 'magento',
            'magento.module' => 'MageZero_OpensearchObservability',
            'magento.version' => $this->magentoVersion,
            'deployment.environment' => $this->config->getApmEnvironment(),
        ];
        foreach ($this->getRequestMeta() as $key => $value) {
            $baseMeta[$key] = $value;
        }
        foreach ($meta as $key => $value) {
            $baseMeta[$key] = (string)$value;
        }

        foreach ($baseMeta as $key => $value) {
            $spanMeta[$key] = (string)$value;
        }

        return $spanMeta;
    }

    /**
     * @return array<string, string>
     */
    protected function getRequestMeta(): array
    {
        if ($this->requestMeta !== null) {
            return $this->requestMeta;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(trim((string)$_SERVER['REQUEST_METHOD'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? trim((string)$_SERVER['REQUEST_URI']) : '';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

        if ($method === '' && $requestUri === '' && $host === '') {
            $this->requestMeta = [];

            return $this->requestMeta;
        }

        $path = '';
        if ($requestUri !== '') {
            $parsedPath = parse_url($requestUri, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $path = $parsedPath;
            }
        }
        if ($path === '' && $requestUri !== '') {
            $path = strtok($requestUri, '?') ?: $requestUri;
        }

        $scheme = 'http';
        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '') {
            $scheme = strtolower(trim((string)explode(',', $forwardedProto)[0]));
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        }

        $requestUrl = '';
        if ($host !== '' && $path !== '') {
            $requestUrl = $scheme . '://' . $host . $path;
        }

        $meta = [];
        if ($method !== '') {
            $meta['magento.request.method'] = $method;
        }
        if ($host !== '') {
            $meta['magento.request.host'] = $host;
        }
        if ($path !== '') {
            $meta['magento.request.path'] = $path;
        }
        if ($requestUri !== '') {
            $meta['magento.request.uri'] = $requestUri;
        }
        if ($requestUrl !== '') {
            $meta['magento.request.url'] = $requestUrl;
        }

        $this->requestMeta = $meta;

        return $this->requestMeta;
    }

    protected function resolveObserverCount(string $eventName): int
    {
        if ($eventName === '') {
            return 0;
        }

        try {
            $observers = $this->eventConfig->getObservers($eventName);
            if (is_array($observers)) {
                return count($observers);
            }
            if ($observers instanceof \Countable) {
                return count($observers);
            }
        } catch (Throwable $exception) {
            return 0;
        }

        return 0;
    }
}

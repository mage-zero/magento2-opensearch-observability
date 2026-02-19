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
        $this->registered = true;

        if (!$this->config->isApmEnabled() || !$this->isTraceMethodAvailable()) {
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
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        @ini_set('datadog.trace.enabled', '1');

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        @ini_set('datadog.trace.sample_rate', (string)$this->config->getTransactionSampleRate());

        $serviceName = $this->config->getResolvedServiceName();
        if ($serviceName !== '') {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            @ini_set('datadog.service', $serviceName);
        }

        $environment = $this->config->getApmEnvironment();
        if ($environment !== '') {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            @ini_set('datadog.env', $environment);
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
    private function decorateSpan($span, string $name, array $meta): void
    {
        if (!is_object($span)) {
            return;
        }

        try {
            if (property_exists($span, 'name')) {
                $span->name = $name;
            }
            if (property_exists($span, 'resource')) {
                $span->resource = $name;
            }
            if (property_exists($span, 'type')) {
                $span->type = 'custom';
            }
            if (property_exists($span, 'service')) {
                $serviceName = $this->config->getResolvedServiceName();
                if ($serviceName !== '') {
                    $span->service = $serviceName;
                }
            }

            $spanMeta = [];
            if (isset($span->meta) && is_array($span->meta)) {
                $spanMeta = $span->meta;
            }

            $spanMeta['component'] = 'magento';
            $spanMeta['magento.module'] = 'MageZero_OpensearchObservability';
            $spanMeta['magento.version'] = $this->magentoVersion;
            $spanMeta['deployment.environment'] = $this->config->getApmEnvironment();

            foreach ($meta as $key => $value) {
                $spanMeta[$key] = (string)$value;
            }

            $span->meta = $spanMeta;
        } catch (Throwable $exception) {
            // No-op. Observability must be fail-open.
        }
    }

    private function resolveObserverCount(string $eventName): int
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

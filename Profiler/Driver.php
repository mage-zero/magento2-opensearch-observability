<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Profiler;

use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use MageZero\OpensearchObservability\Model\Apm\AgentOptionsBuilder;
use MageZero\OpensearchObservability\Model\Apm\BootstrapOptionsReader;
use MageZero\OpensearchObservability\Model\Config as ModuleConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Profiler\DriverInterface;
use Nipwaayoni\AgentBuilder;
use Nipwaayoni\ApmAgent;
use Nipwaayoni\Config as AgentConfig;
use Nipwaayoni\Events\Span;
use Nipwaayoni\Events\Transaction;
use Throwable;

class Driver implements DriverInterface
{
    private const INIT_STATE_PENDING = 'pending';
    private const INIT_STATE_READY = 'ready';
    private const INIT_STATE_DISABLED = 'disabled';

    /**
     * @var array<string, mixed>
     */
    private $driverConfig;

    /**
     * @var ApmAgent|null
     */
    private $agent;

    /**
     * @var Transaction|null
     */
    private $transaction;

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
     * @var BootstrapOptionsReader
     */
    private $bootstrapOptionsReader;

    /**
     * @var Span[]
     */
    private static $callStack = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?BootstrapOptionsReader $bootstrapOptionsReader = null)
    {
        $this->driverConfig = $config;
        $this->agent = null;
        $this->transaction = null;
        $this->enabled = false;
        $this->initState = self::INIT_STATE_PENDING;
        $this->shutdownRegistered = false;
        $this->bootstrapOptionsReader = $bootstrapOptionsReader ?: new BootstrapOptionsReader();

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

        if (!$this->enabled || $this->agent === null || $this->transaction === null) {
            return;
        }

        try {
            $event = $this->createSpan((string)$timerId, $tags ?: []);
            $event->start();
            self::$callStack[] = $event;
        } catch (Throwable $exception) {
            // No-op. Observability should never break core request execution.
        }
    }

    /**
     * @param mixed $timerId
     */
    public function stop($timerId): void
    {
        $timerId = (string)$timerId;
        $this->attemptInitialize();

        if (!$this->enabled || $this->agent === null) {
            return;
        }

        $event = array_pop(self::$callStack);
        if (!$event instanceof Span) {
            return;
        }

        try {
            $event->stop();
            $this->agent->putEvent($event);
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

        self::$callStack = [];
    }

    public function send(): void
    {
        $this->attemptInitialize();

        if (!$this->enabled || $this->agent === null || $this->transaction === null) {
            return;
        }

        try {
            $statusCode = http_response_code();
            $status = $statusCode ? (string)$statusCode : '200';
            $this->agent->stopTransaction($this->transaction->getTransactionName(), ['status' => $status]);
            $this->agent->send();
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

        try {
            $this->agent = $this->createAgentFromOptions($options);
            $this->transaction = $this->agent->startTransaction($this->resolveTransactionName());
            $this->enabled = true;
            $this->initState = self::INIT_STATE_READY;
            $this->registerShutdownHandler();
        } catch (Throwable $exception) {
            $this->enabled = false;
            $this->agent = null;
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
     * @param array<string, mixed> $options
     */
    protected function createAgentFromOptions(array $options): ApmAgent
    {
        $agentConfig = new AgentConfig($options);

        return (new AgentBuilder())
            ->withConfig($agentConfig)
            ->withHttpClient(new GuzzleAdapter())
            ->build();
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
     */
    private function createSpan(string $timerId, array $tags): Span
    {
        $shortTimerId = $this->shortenTimerId($timerId);
        $callDepth = count(self::$callStack);
        $parent = $callDepth > 0 ? self::$callStack[$callDepth - 1] : $this->transaction;

        /** @var Span $event */
        $event = $this->agent->factory()->newSpan($shortTimerId, $parent);
        $event->setType('app.internal');

        if (strpos($shortTimerId, 'DB_QUERY') !== false && isset($tags['statement'])) {
            $event->setType('db.mysql.query');
            $event->setAction('query');
            $event->setCustomContext([
                'db' => [
                    'statement' => (string)$tags['statement'],
                    'type' => 'sql',
                ],
            ]);
        }

        return $event;
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
        ];

        foreach ($supportedKeys as $key) {
            if (!array_key_exists($key, $this->driverConfig)) {
                continue;
            }

            $options[$key] = $this->driverConfig[$key];
        }
    }
}

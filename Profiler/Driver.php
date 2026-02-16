<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Profiler;

use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use MageZero\OpensearchObservability\Model\Apm\AgentOptionsBuilder;
use MageZero\OpensearchObservability\Model\Config as ModuleConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Profiler\DriverInterface;
use Nipwaayoni\AgentBuilder;
use Nipwaayoni\ApmAgent;
use Nipwaayoni\Config as AgentConfig;
use Nipwaayoni\Events\Span;
use Nipwaayoni\Events\Transaction;
use Throwable;

class Driver implements DriverInterface
{
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
     * @var Span[]
     */
    private static $callStack = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->driverConfig = $config;
        $this->agent = null;
        $this->transaction = null;
        $this->enabled = false;

        $this->init();

        if ($this->enabled) {
            register_shutdown_function([$this, 'send']);
        }
    }

    public function init(): void
    {
        try {
            $objectManager = ObjectManager::getInstance();
            /** @var ModuleConfig $moduleConfig */
            $moduleConfig = $objectManager->get(ModuleConfig::class);
            if (!$moduleConfig->isApmEnabled()) {
                return;
            }

            /** @var AgentOptionsBuilder $optionsBuilder */
            $optionsBuilder = $objectManager->get(AgentOptionsBuilder::class);
            $options = $optionsBuilder->build($_SERVER);
            $this->applyDriverOverrides($options);

            if (empty($options['serverUrl'])) {
                return;
            }

            $agentConfig = new AgentConfig($options);

            $this->agent = (new AgentBuilder())
                ->withConfig($agentConfig)
                ->withHttpClient(new GuzzleAdapter())
                ->build();

            $this->transaction = $this->agent->startTransaction($this->resolveTransactionName());
            $this->enabled = true;
        } catch (Throwable $exception) {
            $this->enabled = false;
            $this->agent = null;
            $this->transaction = null;
        }
    }

    /**
     * @param mixed $timerId
     * @param array<string, mixed>|null $tags
     */
    public function start($timerId, array $tags = null): void
    {
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
        self::$callStack = [];
    }

    public function send(): void
    {
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
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
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

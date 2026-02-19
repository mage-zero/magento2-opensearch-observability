<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Profiler;

use MageZero\OpensearchObservability\Profiler\Driver;
use Magento\Framework\ObjectManagerInterface;
use Nipwaayoni\ApmAgent;
use Nipwaayoni\Events\Transaction;
use PHPUnit\Framework\TestCase;

class DriverTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testRetriesInitializationWhenObjectManagerBecomesAvailable(): void
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getTransactionName')->willReturn('GET /');

        $agent = $this->createMock(ApmAgent::class);
        $agent->expects($this->once())
            ->method('startTransaction')
            ->with('GET /')
            ->willReturn($transaction);

        $driver = new TestableDriver(
            $agent,
            null,
            [
                'enabled' => true,
                'serverUrl' => 'http://apm-server:8200',
                'serviceName' => 'module-config-service',
                'hostname' => 'app-node-1',
                'environment' => 'production',
                'transactionSampleRate' => 1.0,
                'stackTraceLimit' => 1000,
                'timeout' => 10,
            ],
            []
        );

        $this->assertSame(0, $driver->createAgentCalls);

        $driver->objectManager = $this->createMock(ObjectManagerInterface::class);
        $driver->stop('timer');

        $this->assertSame(1, $driver->createAgentCalls);
        $this->assertSame('http://apm-server:8200', $driver->lastOptions['serverUrl']);
        $this->assertSame('module-config-service', $driver->lastOptions['serviceName']);
    }

    public function testInitializesFromBootstrapOptionsWithoutObjectManager(): void
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getTransactionName')->willReturn('GET /');

        $agent = $this->createMock(ApmAgent::class);
        $agent->expects($this->once())
            ->method('startTransaction')
            ->with('GET /')
            ->willReturn($transaction);

        $driver = new TestableDriver(
            $agent,
            null,
            [],
            [
                'enabled' => true,
                'serverUrl' => 'http://legacy-apm-server:8200',
                'serviceName' => 'legacy-bootstrap-service',
                'hostname' => 'legacy-host',
                'environment' => 'local',
                'transactionSampleRate' => 0.25,
                'stackTraceLimit' => 50,
                'timeout' => 5,
            ]
        );

        $this->assertSame(1, $driver->createAgentCalls);
        $this->assertSame('http://legacy-apm-server:8200', $driver->lastOptions['serverUrl']);
        $this->assertSame('legacy-bootstrap-service', $driver->lastOptions['serviceName']);
        $this->assertSame('local', $driver->lastOptions['environment']);
    }
}

class TestableDriver extends Driver
{
    /**
     * @var ObjectManagerInterface|null
     */
    public $objectManager;

    /**
     * @var array<string, mixed>
     */
    public $moduleOptions;

    /**
     * @var array<string, mixed>
     */
    public $bootstrapOptions;

    /**
     * @var ApmAgent
     */
    private $agentInstance;

    /**
     * @var int
     */
    public $createAgentCalls = 0;

    /**
     * @var array<string, mixed>
     */
    public $lastOptions = [];

    /**
     * @param array<string, mixed> $moduleOptions
     * @param array<string, mixed> $bootstrapOptions
     */
    public function __construct(
        ApmAgent $agent,
        ?ObjectManagerInterface $objectManager,
        array $moduleOptions,
        array $bootstrapOptions
    ) {
        $this->agentInstance = $agent;
        $this->objectManager = $objectManager;
        $this->moduleOptions = $moduleOptions;
        $this->bootstrapOptions = $bootstrapOptions;
        parent::__construct();
    }

    protected function registerShutdownHandler(): void
    {
        // No-op for unit tests.
    }

    protected function getObjectManagerInstance(): ?ObjectManagerInterface
    {
        return $this->objectManager;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildOptionsFromModuleConfig(ObjectManagerInterface $objectManager): array
    {
        return $this->moduleOptions;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readBootstrapOptions(): array
    {
        return $this->bootstrapOptions;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function createAgentFromOptions(array $options): ApmAgent
    {
        $this->createAgentCalls++;
        $this->lastOptions = $options;

        return $this->agentInstance;
    }
}

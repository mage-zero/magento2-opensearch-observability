<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Profiler;

use MageZero\OpensearchObservability\Profiler\Driver;
use Magento\Framework\ObjectManagerInterface;
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
        $_SERVER['HTTP_HOST'] = 'example.test';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testRetriesInitializationWhenObjectManagerBecomesAvailable(): void
    {
        $driver = new TestableDriver(
            null,
            [
                'enabled' => true,
                'serverUrl' => 'http://otel-collector:4318/v1/traces',
                'serviceName' => 'module-config-service',
                'hostname' => 'app-node-1',
                'environment' => 'production',
                'transactionSampleRate' => 1.0,
                'stackTraceLimit' => 1000,
                'timeout' => 10,
            ],
            []
        );

        $this->assertSame(0, $driver->emitCalls);

        $driver->objectManager = $this->createMock(ObjectManagerInterface::class);
        $driver->start('profile>timer-a', ['group' => 'app']);
        $driver->stop('profile>timer-a');
        $driver->send();

        $this->assertSame(1, $driver->emitCalls);
        $this->assertSame('http://otel-collector:4318/v1/traces', $driver->lastOptions['serverUrl']);
        $this->assertSame('module-config-service', $driver->lastOptions['serviceName']);
        $this->assertNotEmpty($driver->lastPayload['resourceSpans'] ?? []);
    }

    public function testInitializesFromBootstrapOptionsWithoutObjectManager(): void
    {
        $driver = new TestableDriver(
            null,
            [],
            [
                'enabled' => true,
                'serverUrl' => 'http://legacy-collector:4318/v1/traces',
                'serviceName' => 'legacy-bootstrap-service',
                'hostname' => 'legacy-host',
                'environment' => 'local',
                'transactionSampleRate' => 0.25,
                'stackTraceLimit' => 50,
                'timeout' => 5,
            ]
        );

        $driver->start('profile>timer-a', null);
        $driver->stop('profile>timer-a');
        $driver->send();

        $this->assertSame(1, $driver->emitCalls);
        $this->assertSame('http://legacy-collector:4318/v1/traces', $driver->lastOptions['serverUrl']);
        $this->assertSame('legacy-bootstrap-service', $driver->lastOptions['serviceName']);
        $this->assertSame('local', $driver->lastOptions['environment']);
        $this->assertNotEmpty($driver->lastPayload['resourceSpans'] ?? []);
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
     * @var int
     */
    public $emitCalls = 0;

    /**
     * @var array<string, mixed>
     */
    public $lastPayload = [];

    /**
     * @var array<string, mixed>
     */
    public $lastOptions = [];

    /**
     * @param array<string, mixed> $moduleOptions
     * @param array<string, mixed> $bootstrapOptions
     */
    public function __construct(
        ?ObjectManagerInterface $objectManager,
        array $moduleOptions,
        array $bootstrapOptions
    ) {
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
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    protected function emitPayload(array $payload, array $options): void
    {
        $this->emitCalls++;
        $this->lastPayload = $payload;
        $this->lastOptions = $options;
    }
}

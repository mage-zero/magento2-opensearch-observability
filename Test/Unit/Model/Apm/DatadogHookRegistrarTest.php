<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;
use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Event\ConfigInterface as EventConfigInterface;
use PHPUnit\Framework\TestCase;

class DatadogHookRegistrarTest extends TestCase
{
    public function testRegisterSkipsWhenApmIsDisabled(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => false,
        ]);

        $registrar->register();
        $this->assertSame([], $registrar->hooks);
    }

    public function testRegisterSkipsWhenDdtraceHookApiIsUnavailable(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => true,
        ]);
        $registrar->traceMethodAvailable = false;

        $registrar->register();
        $this->assertSame([], $registrar->hooks);
    }

    public function testRegisterAddsDefaultEventAndLayoutHooks(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => true,
            'span_events_enabled' => true,
            'span_layout_enabled' => true,
            'span_plugins_enabled' => false,
            'span_di_enabled' => false,
        ]);

        $registrar->register();

        $this->assertSame([
            'Magento\\Framework\\Event\\Manager::dispatch',
            'Magento\\Framework\\View\\Layout::renderElement',
        ], $registrar->hooks);
    }

    public function testRegisterAddsPluginAndDiHooksWhenEnabled(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => true,
            'span_events_enabled' => true,
            'span_layout_enabled' => true,
            'span_plugins_enabled' => true,
            'span_di_enabled' => true,
        ]);

        $registrar->register();

        $this->assertSame([
            'Magento\\Framework\\Event\\Manager::dispatch',
            'Magento\\Framework\\View\\Layout::renderElement',
            'Magento\\Framework\\Interception\\PluginList\\PluginList::getNext',
            'Magento\\Framework\\ObjectManager\\ObjectManager::create',
            'Magento\\Framework\\ObjectManager\\ObjectManager::get',
        ], $registrar->hooks);
    }

    public function testRegisterIsIdempotentWithinProcess(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => true,
            'span_events_enabled' => true,
            'span_layout_enabled' => true,
            'span_plugins_enabled' => false,
            'span_di_enabled' => false,
        ]);

        $registrar->register();
        $registrar->register();

        $this->assertCount(2, $registrar->hooks);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildRegistrar(array $overrides): InspectableDatadogHookRegistrar
    {
        $config = $this->createMock(Config::class);
        $config->method('isApmEnabled')->willReturn((bool)($overrides['apm_enabled'] ?? true));
        $config->method('isApmSpanEventsEnabled')->willReturn((bool)($overrides['span_events_enabled'] ?? true));
        $config->method('isApmSpanLayoutEnabled')->willReturn((bool)($overrides['span_layout_enabled'] ?? true));
        $config->method('isApmSpanPluginsEnabled')->willReturn((bool)($overrides['span_plugins_enabled'] ?? false));
        $config->method('isApmSpanDiEnabled')->willReturn((bool)($overrides['span_di_enabled'] ?? false));
        $config->method('getTransactionSampleRate')->willReturn((float)($overrides['sample_rate'] ?? 1.0));
        $config->method('getResolvedServiceName')->willReturn((string)($overrides['service_name'] ?? 'magento'));
        $config->method('getApmEnvironment')->willReturn((string)($overrides['environment'] ?? 'production'));

        $eventConfig = $this->createMock(EventConfigInterface::class);
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.8-p3');

        return new InspectableDatadogHookRegistrar($config, $eventConfig, $productMetadata);
    }
}

class InspectableDatadogHookRegistrar extends DatadogHookRegistrar
{
    /**
     * @var bool
     */
    public $traceMethodAvailable = true;

    /**
     * @var array<int, string>
     */
    public $hooks = [];

    protected function isTraceMethodAvailable(): bool
    {
        return $this->traceMethodAvailable;
    }

    protected function registerMethodHook(string $className, string $methodName, callable $hook): bool
    {
        $this->hooks[] = $className . '::' . $methodName;
        return true;
    }
}

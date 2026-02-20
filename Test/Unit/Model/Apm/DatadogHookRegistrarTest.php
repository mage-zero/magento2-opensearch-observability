<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Apm;

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

    public function testRegisterCanRetryAfterEarlyDisabledState(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->exactly(2))
            ->method('isApmEnabled')
            ->willReturnOnConsecutiveCalls(false, true);
        $config->method('isApmSpanEventsEnabled')->willReturn(true);
        $config->method('isApmSpanLayoutEnabled')->willReturn(true);
        $config->method('isApmSpanPluginsEnabled')->willReturn(false);
        $config->method('isApmSpanDiEnabled')->willReturn(false);
        $config->method('getTransactionSampleRate')->willReturn(1.0);
        $config->method('getResolvedServiceName')->willReturn('magento');
        $config->method('getApmEnvironment')->willReturn('production');

        $eventConfig = $this->createMock(EventConfigInterface::class);
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.8-p3');

        $registrar = new InspectableDatadogHookRegistrar($config, $eventConfig, $productMetadata);
        $registrar->register();
        $this->assertSame([], $registrar->hooks);

        $registrar->register();
        $this->assertSame([
            'Magento\\Framework\\Event\\Manager::dispatch',
            'Magento\\Framework\\View\\Layout::renderElement',
        ], $registrar->hooks);
    }

    public function testDecorateSpanAddsResolvedRequestMetadata(): void
    {
        $registrar = $this->buildRegistrar([
            'apm_enabled' => true,
            'service_name' => 'web-presence',
            'environment' => 'development',
        ]);
        $registrar->requestMetaOverride = [
            'magento.request.method' => 'GET',
            'magento.request.path' => '/customer/account/login',
            'magento.request.uri' => '/customer/account/login/?from=menu',
            'magento.request.url' => 'https://localhost.magezero.com/customer/account/login/',
        ];

        $span = new \stdClass();
        $span->meta = [
            'existing.key' => 'existing-value',
        ];
        $span->name = 'original';
        $span->resource = 'original';
        $span->type = 'web';
        $span->service = 'unknown';

        $registrar->applyDecorateSpan($span, 'magento.layout.render', [
            'magento.layout.element' => 'header.container',
        ]);

        $this->assertSame('magento.layout.render', $span->name);
        $this->assertSame('magento.layout.render', $span->resource);
        $this->assertSame('custom', $span->type);
        $this->assertSame('web-presence', $span->service);
        $this->assertSame('existing-value', $span->meta['existing.key']);
        $this->assertSame('development', $span->meta['deployment.environment']);
        $this->assertSame('GET', $span->meta['magento.request.method']);
        $this->assertSame('/customer/account/login', $span->meta['magento.request.path']);
        $this->assertSame('/customer/account/login/?from=menu', $span->meta['magento.request.uri']);
        $this->assertSame('https://localhost.magezero.com/customer/account/login/', $span->meta['magento.request.url']);
        $this->assertSame('header.container', $span->meta['magento.layout.element']);
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

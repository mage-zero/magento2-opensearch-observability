<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Apm;

use MageZero\OpensearchObservability\Model\Apm\AgentOptionsBuilder;
use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;

class AgentOptionsBuilderTest extends TestCase
{
    public function testBuildReturnsNormalizedAgentOptions(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isApmEnabled')->willReturn(true);
        $config->method('getApmServerUrl')->willReturn('http://apm-server:8200');
        $config->method('getResolvedServiceName')->willReturn('store-example-com');
        $config->method('getResolvedHostname')->willReturn('app-1');
        $config->method('getApmEnvironment')->willReturn('production');
        $config->method('getTransactionSampleRate')->willReturn(0.5);
        $config->method('getStackTraceLimit')->willReturn(1000);
        $config->method('getTimeoutSeconds')->willReturn(10);
        $config->method('getApmSecretToken')->willReturn('top-secret');

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.8-p3');

        $builder = new AgentOptionsBuilder($config, $productMetadata);
        $options = $builder->build(['HTTP_HOST' => 'store.example.com']);

        $this->assertTrue($options['enabled']);
        $this->assertSame('http://apm-server:8200', $options['serverUrl']);
        $this->assertSame('store-example-com', $options['serviceName']);
        $this->assertSame('app-1', $options['hostname']);
        $this->assertSame('production', $options['environment']);
        $this->assertSame(0.5, $options['transactionSampleRate']);
        $this->assertSame(1000, $options['stackTraceLimit']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame('top-secret', $options['secretToken']);
        $this->assertSame('magento2', $options['frameworkName']);
        $this->assertSame('2.4.8-p3', $options['frameworkVersion']);
    }

    public function testSecretTokenIsOmittedWhenEmpty(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isApmEnabled')->willReturn(false);
        $config->method('getApmServerUrl')->willReturn('');
        $config->method('getResolvedServiceName')->willReturn('magento');
        $config->method('getResolvedHostname')->willReturn('unknown-host');
        $config->method('getApmEnvironment')->willReturn('production');
        $config->method('getTransactionSampleRate')->willReturn(1.0);
        $config->method('getStackTraceLimit')->willReturn(1000);
        $config->method('getTimeoutSeconds')->willReturn(10);
        $config->method('getApmSecretToken')->willReturn('');

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.4-p13');

        $builder = new AgentOptionsBuilder($config, $productMetadata);
        $options = $builder->build();

        $this->assertArrayNotHasKey('secretToken', $options);
    }
}

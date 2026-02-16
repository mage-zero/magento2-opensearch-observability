<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Log;

use MageZero\OpensearchObservability\Model\Config;
use MageZero\OpensearchObservability\Model\Log\StderrEmitter;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;

class StderrEmitterTest extends TestCase
{
    public function testEmitWritesJsonPayloadWhenEnabledAndAboveThreshold(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mz-observability-log-');
        $this->assertNotFalse($tempFile);

        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(true);
        $config->method('getLogStreamMinLevel')->willReturn('warning');
        $config->method('isDirectLogStreamEnabled')->willReturn(false);

        $emitter = new StderrEmitter($config, $tempFile);
        $emitter->emit([
            'message' => 'Order failed',
            'level' => 400,
            'level_name' => 'ERROR',
            'channel' => 'main',
            'context' => ['order_id' => 10],
            'extra' => ['trace_id' => 'abc'],
        ], 'exception.log');

        $lines = file($tempFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_string($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        $payload = json_decode((string)$lines[0], true);
        $this->assertIsArray($payload);
        $this->assertSame('Order failed', $payload['message']);
        $this->assertSame('exception.log', $payload['magento.log_file']);
        $this->assertSame('error', $payload['log.level']);
        $this->assertSame(10, $payload['context']['order_id']);
    }

    public function testEmitSkipsWhenBelowConfiguredLevel(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mz-observability-log-');
        $this->assertNotFalse($tempFile);

        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(true);
        $config->method('getLogStreamMinLevel')->willReturn('error');
        $config->method('isDirectLogStreamEnabled')->willReturn(false);

        $emitter = new StderrEmitter($config, $tempFile);
        $emitter->emit([
            'message' => 'Informational log',
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'main',
            'context' => [],
            'extra' => [],
        ], 'system.log');

        $contents = (string)file_get_contents($tempFile);
        if (is_string($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        $this->assertSame('', $contents);
    }

    public function testEmitSupportsObjectLevelRepresentation(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mz-observability-log-');
        $this->assertNotFalse($tempFile);

        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(true);
        $config->method('getLogStreamMinLevel')->willReturn('warning');
        $config->method('isDirectLogStreamEnabled')->willReturn(false);

        $level = new \stdClass();
        $level->name = 'ERROR';
        $level->value = 400;

        $emitter = new StderrEmitter($config, $tempFile);
        $emitter->emit([
            'message' => 'Exception with object level',
            'level' => $level,
            'channel' => 'main',
            'context' => [],
            'extra' => [],
        ], 'exception.log');

        $lines = file($tempFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_string($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $payload = json_decode((string)$lines[0], true);
        $this->assertSame('error', $payload['log.level']);
    }

    public function testEmitCanPushDirectlyToConfiguredEndpoint(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(true);
        $config->method('getLogStreamMinLevel')->willReturn('warning');
        $config->method('isDirectLogStreamEnabled')->willReturn(true);
        $config->method('getLogStreamDirectUrl')->willReturn('https://opensearch.example.com:9200');
        $config->method('getLogStreamDirectIndex')->willReturn('magento-observability-logs');
        $config->method('getLogStreamDirectApiKey')->willReturn('my-api-key');
        $config->method('getLogStreamDirectTimeoutMs')->willReturn(500);
        $config->method('shouldVerifyLogStreamDirectTls')->willReturn(true);

        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('setHeaders');
        $curl->expects($this->once())
            ->method('addHeader')
            ->with('Authorization', 'ApiKey my-api-key');
        $curl->expects($this->never())->method('setCredentials');
        $curl->expects($this->once())
            ->method('post')
            ->with(
                'https://opensearch.example.com:9200/magento-observability-logs/_doc',
                $this->callback(static function (string $payload): bool {
                    return strpos($payload, '"message":"Direct log test"') !== false;
                })
            );

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->expects($this->once())->method('create')->willReturn($curl);

        $emitter = new StderrEmitter($config, 'php://stderr', $curlFactory);
        $emitter->emit([
            'message' => 'Direct log test',
            'level' => 400,
            'level_name' => 'ERROR',
            'channel' => 'main',
            'context' => [],
            'extra' => [],
        ], 'exception.log');
    }
}

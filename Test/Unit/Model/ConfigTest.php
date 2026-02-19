<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model;

use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testDefaultFlagsAreDisabled(): void
    {
        $scopeConfig = $this->buildScopeConfig([], []);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertFalse($config->isApmEnabled());
        $this->assertFalse($config->isApmSpanEventsEnabled());
        $this->assertFalse($config->isApmSpanLayoutEnabled());
        $this->assertFalse($config->isApmSpanPluginsEnabled());
        $this->assertFalse($config->isApmSpanDiEnabled());
        $this->assertFalse($config->isLogStreamingEnabled());
        $this->assertSame('warning', $config->getLogStreamMinLevel());
        $this->assertSame('stderr', $config->getLogStreamTransport());
    }

    public function testTransactionSampleRateIsClamped(): void
    {
        $scopeConfig = $this->buildScopeConfig([
            Config::XML_PATH_APM_TRANSACTION_SAMPLE_RATE => '7.2',
        ], []);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertSame(1.0, $config->getTransactionSampleRate());
    }

    public function testResolvedServiceNamePrefersConfiguredValue(): void
    {
        $scopeConfig = $this->buildScopeConfig([
            Config::XML_PATH_APM_SERVICE_NAME => 'my custom service',
        ], []);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertSame('my-custom-service', $config->getResolvedServiceName(['HTTP_HOST' => 'example.com']));
    }

    public function testResolvedServiceNameFallsBackToHost(): void
    {
        $scopeConfig = $this->buildScopeConfig([], []);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertSame('store-example-com-443', $config->getResolvedServiceName(['HTTP_HOST' => 'store.example.com:443']));
    }

    public function testLogLevelFallsBackWhenInvalid(): void
    {
        $scopeConfig = $this->buildScopeConfig([
            Config::XML_PATH_LOG_STREAM_MIN_LEVEL => 'not-a-level',
        ], []);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertSame('warning', $config->getLogStreamMinLevel());
    }

    public function testSpanFlagsRespectConfiguredValues(): void
    {
        $scopeConfig = $this->buildScopeConfig([], [
            Config::XML_PATH_APM_SPAN_EVENTS_ENABLED => true,
            Config::XML_PATH_APM_SPAN_LAYOUT_ENABLED => false,
            Config::XML_PATH_APM_SPAN_PLUGINS_ENABLED => true,
            Config::XML_PATH_APM_SPAN_DI_ENABLED => false,
        ]);
        $config = new Config($scopeConfig, $this->buildEncryptor());

        $this->assertTrue($config->isApmSpanEventsEnabled());
        $this->assertFalse($config->isApmSpanLayoutEnabled());
        $this->assertTrue($config->isApmSpanPluginsEnabled());
        $this->assertFalse($config->isApmSpanDiEnabled());
    }

    public function testLogTransportFallsBackToStderrWhenInvalid(): void
    {
        $scopeConfig = $this->buildScopeConfig([
            Config::XML_PATH_LOG_STREAM_TRANSPORT => 'invalid',
        ], []);

        $config = new Config($scopeConfig, $this->buildEncryptor());
        $this->assertSame('stderr', $config->getLogStreamTransport());
        $this->assertFalse($config->isDirectLogStreamEnabled());
    }

    public function testDirectLogConfigIsNormalizedAndDecrypted(): void
    {
        $scopeConfig = $this->buildScopeConfig([
            Config::XML_PATH_LOG_STREAM_TRANSPORT => 'direct',
            Config::XML_PATH_LOG_STREAM_DIRECT_URL => ' https://logs.example.com:9200/ ',
            Config::XML_PATH_LOG_STREAM_DIRECT_INDEX => ' Magento Logs! ',
            Config::XML_PATH_LOG_STREAM_DIRECT_API_KEY => 'encrypted-api-key',
            Config::XML_PATH_LOG_STREAM_DIRECT_USERNAME => ' app ',
            Config::XML_PATH_LOG_STREAM_DIRECT_PASSWORD => 'encrypted-password',
            Config::XML_PATH_LOG_STREAM_DIRECT_TIMEOUT_MS => '42',
        ], [
            Config::XML_PATH_LOG_STREAM_DIRECT_VERIFY_TLS => true,
        ]);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static function (string $value): string {
                if ($value === 'encrypted-api-key') {
                    return 'decrypted-api-key';
                }

                if ($value === 'encrypted-password') {
                    return 'decrypted-password';
                }

                return $value;
            }
        );

        $config = new Config($scopeConfig, $encryptor);

        $this->assertTrue($config->isDirectLogStreamEnabled());
        $this->assertSame('https://logs.example.com:9200', $config->getLogStreamDirectUrl());
        $this->assertSame('magento-logs', $config->getLogStreamDirectIndex());
        $this->assertSame('decrypted-api-key', $config->getLogStreamDirectApiKey());
        $this->assertSame('app', $config->getLogStreamDirectUsername());
        $this->assertSame('decrypted-password', $config->getLogStreamDirectPassword());
        $this->assertSame(100, $config->getLogStreamDirectTimeoutMs());
        $this->assertTrue($config->shouldVerifyLogStreamDirectTls());
    }

    /**
     * @param array<string, string> $values
     * @param array<string, bool> $flags
     */
    private function buildScopeConfig(array $values, array $flags): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($values) {
                return $values[$path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($flags) {
                return $flags[$path] ?? false;
            }
        );

        return $scopeConfig;
    }

    private function buildEncryptor(): EncryptorInterface
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static function (string $value): string {
                return $value;
            }
        );

        return $encryptor;
    }
}

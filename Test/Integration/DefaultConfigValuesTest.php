<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Integration;

use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

class DefaultConfigValuesTest extends TestCase
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = Bootstrap::getObjectManager()->get(ScopeConfigInterface::class);
    }

    public function testFeatureSwitchesAreDisabledByDefault(): void
    {
        $this->assertSame('0', (string)$this->scopeConfig->getValue(Config::XML_PATH_APM_ENABLED));
        $this->assertSame('0', (string)$this->scopeConfig->getValue(Config::XML_PATH_APM_DB_PROFILER_ENABLED));
        $this->assertSame('0', (string)$this->scopeConfig->getValue(Config::XML_PATH_LOG_STREAM_ENABLED));
    }

    public function testApmDefaultsAreDefined(): void
    {
        $this->assertSame('production', (string)$this->scopeConfig->getValue(Config::XML_PATH_APM_ENVIRONMENT));
        $this->assertSame('1.0', (string)$this->scopeConfig->getValue(Config::XML_PATH_APM_TRANSACTION_SAMPLE_RATE));
        $this->assertSame('1000', (string)$this->scopeConfig->getValue(Config::XML_PATH_APM_STACK_TRACE_LIMIT));
    }

    public function testLogStreamingTransportDefaultsAreDefined(): void
    {
        $this->assertSame('stderr', (string)$this->scopeConfig->getValue(Config::XML_PATH_LOG_STREAM_TRANSPORT));
        $this->assertSame(
            'magento-observability-logs',
            (string)$this->scopeConfig->getValue(Config::XML_PATH_LOG_STREAM_DIRECT_INDEX)
        );
        $this->assertSame('500', (string)$this->scopeConfig->getValue(Config::XML_PATH_LOG_STREAM_DIRECT_TIMEOUT_MS));
        $this->assertSame('1', (string)$this->scopeConfig->getValue(Config::XML_PATH_LOG_STREAM_DIRECT_VERIFY_TLS));
    }
}

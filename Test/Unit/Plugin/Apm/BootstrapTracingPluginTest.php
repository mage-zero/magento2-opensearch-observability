<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Plugin\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;
use MageZero\OpensearchObservability\Plugin\Apm\BootstrapTracingPlugin;
use Magento\Framework\App\Bootstrap;
use PHPUnit\Framework\TestCase;

class BootstrapTracingPluginTest extends TestCase
{
    public function testBeforeRunRegistersTracingHooksAndPreservesArguments(): void
    {
        $registrar = $this->createMock(DatadogHookRegistrar::class);
        $registrar->expects($this->once())->method('register');

        $plugin = new BootstrapTracingPlugin($registrar);
        $subject = $this->getMockBuilder(Bootstrap::class)
            ->disableOriginalConstructor()
            ->getMock();

        $application = new \stdClass();
        $result = $plugin->beforeRun($subject, $application);

        $this->assertSame([$application], $result);
    }
}

<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Plugin\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;
use MageZero\OpensearchObservability\Plugin\Apm\FrontControllerTracingPlugin;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class FrontControllerTracingPluginTest extends TestCase
{
    public function testBeforeDispatchRegistersTracingHooksAndPreservesArguments(): void
    {
        $registrar = $this->createMock(DatadogHookRegistrar::class);
        $registrar->expects($this->once())->method('register');

        $plugin = new FrontControllerTracingPlugin($registrar);
        $subject = $this->getMockBuilder(FrontController::class)
            ->disableOriginalConstructor()
            ->getMock();
        $request = $this->createMock(RequestInterface::class);

        $result = $plugin->beforeDispatch($subject, $request);

        $this->assertSame([$request], $result);
    }
}


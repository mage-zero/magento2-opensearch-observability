<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Plugin\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;

class FrontControllerTracingPlugin
{
    /**
     * @var DatadogHookRegistrar
     */
    private $hookRegistrar;

    public function __construct(DatadogHookRegistrar $hookRegistrar)
    {
        $this->hookRegistrar = $hookRegistrar;
    }

    /**
     * @return array<int, RequestInterface>
     */
    public function beforeDispatch(FrontControllerInterface $subject, RequestInterface $request): array
    {
        unset($subject);
        $this->hookRegistrar->register();

        return [$request];
    }
}


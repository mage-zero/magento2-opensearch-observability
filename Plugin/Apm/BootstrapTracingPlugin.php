<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Plugin\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;

class BootstrapTracingPlugin
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
     * @param mixed $application
     * @return array<int, mixed>
     */
    public function beforeRun(\Magento\Framework\App\Bootstrap $subject, $application): array
    {
        $this->hookRegistrar->register();

        return [$application];
    }
}

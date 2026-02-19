<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Apm;

use MageZero\OpensearchObservability\Model\Apm\DatadogHookRegistrar;

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

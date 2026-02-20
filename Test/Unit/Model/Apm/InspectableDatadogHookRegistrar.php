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

    /**
     * @var array<string, string>|null
     */
    public $requestMetaOverride;

    protected function isTraceMethodAvailable(): bool
    {
        return $this->traceMethodAvailable;
    }

    protected function registerMethodHook(string $className, string $methodName, callable $hook): bool
    {
        $this->hooks[] = $className . '::' . $methodName;
        return true;
    }

    /**
     * @param mixed $span
     * @param array<string, string> $meta
     */
    public function applyDecorateSpan($span, string $name, array $meta): void
    {
        $this->decorateSpan($span, $name, $meta);
    }

    /**
     * @return array<string, string>
     */
    protected function getRequestMeta(): array
    {
        if ($this->requestMetaOverride !== null) {
            return $this->requestMetaOverride;
        }

        return parent::getRequestMeta();
    }
}

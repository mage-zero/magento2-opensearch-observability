<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogLevel implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'debug', 'label' => __('Debug')],
            ['value' => 'info', 'label' => __('Info')],
            ['value' => 'notice', 'label' => __('Notice')],
            ['value' => 'warning', 'label' => __('Warning')],
            ['value' => 'error', 'label' => __('Error')],
            ['value' => 'critical', 'label' => __('Critical')],
            ['value' => 'alert', 'label' => __('Alert')],
            ['value' => 'emergency', 'label' => __('Emergency')],
        ];
    }
}

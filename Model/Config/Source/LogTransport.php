<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogTransport implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'stderr', 'label' => __('STDERR (collector-managed)')],
            ['value' => 'direct', 'label' => __('Direct HTTP (OpenSearch/Elasticsearch)')],
        ];
    }
}

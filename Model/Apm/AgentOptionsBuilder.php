<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Apm;

use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\App\ProductMetadataInterface;

class AgentOptionsBuilder
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    public function __construct(
        Config $config,
        ProductMetadataInterface $productMetadata
    ) {
        $this->config = $config;
        $this->productMetadata = $productMetadata;
    }

    /**
     * @param array<string, string> $server
     * @return array<string, mixed>
     */
    public function build(array $server = []): array
    {
        $options = [
            'enabled' => $this->config->isApmEnabled(),
            'serverUrl' => $this->config->getApmServerUrl(),
            'serviceName' => $this->config->getResolvedServiceName($server),
            'hostname' => $this->config->getResolvedHostname($server),
            'environment' => $this->config->getApmEnvironment(),
            'transactionSampleRate' => $this->config->getTransactionSampleRate(),
            'stackTraceLimit' => $this->config->getStackTraceLimit(),
            'timeout' => $this->config->getTimeoutSeconds(),
            'frameworkName' => 'magento2',
            'frameworkVersion' => (string)$this->productMetadata->getVersion(),
        ];

        $token = $this->config->getApmSecretToken();
        if ($token !== '') {
            $options['secretToken'] = $token;
        }

        return $options;
    }
}

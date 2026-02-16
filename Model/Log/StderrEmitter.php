<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Model\Log;

use DateTimeInterface;
use MageZero\OpensearchObservability\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;

class StderrEmitter
{
    /**
     * Emits normalized log payloads to the configured transport (stderr or direct HTTP).
     */
    /**
     * @var Config
     */
    private $config;

    /**
     * @var string
     */
    private $streamPath;

    /**
     * @var resource|null
     */
    private $streamHandle;

    /**
     * @var CurlFactory|null
     */
    private $curlFactory;

    /**
     * @param string $streamPath
     */
    public function __construct(
        Config $config,
        $streamPath = 'php://stderr',
        ?CurlFactory $curlFactory = null
    )
    {
        $this->config = $config;
        $this->streamPath = (string)$streamPath;
        $this->streamHandle = null;
        $this->curlFactory = $curlFactory;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function emit(array $record, string $logFile): void
    {
        if (!$this->config->isLogStreamingEnabled()) {
            return;
        }

        if (!$this->isAllowedByLevel($record)) {
            return;
        }

        $payload = [
            '@timestamp' => $this->extractTimestamp($record),
            'message' => isset($record['message']) ? (string)$record['message'] : '',
            'log.level' => $this->extractLevelName($record),
            'magento.log_file' => $logFile,
            'magento.channel' => isset($record['channel']) ? (string)$record['channel'] : 'main',
            'context' => isset($record['context']) && is_array($record['context']) ? $record['context'] : [],
            'extra' => isset($record['extra']) && is_array($record['extra']) ? $record['extra'] : [],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        if ($this->config->isDirectLogStreamEnabled()) {
            $this->emitToDirectEndpoint($encoded);
            return;
        }

        $this->emitToStderr($encoded);
    }

    private function emitToStderr(string $encoded): void
    {
        $stream = $this->getStreamHandle();
        if (!is_resource($stream)) {
            return;
        }

        fwrite($stream, $encoded . PHP_EOL);
    }

    private function emitToDirectEndpoint(string $encoded): void
    {
        if ($this->curlFactory === null) {
            return;
        }

        $baseUrl = $this->config->getLogStreamDirectUrl();
        if ($baseUrl === '') {
            return;
        }

        $index = $this->config->getLogStreamDirectIndex();
        $endpoint = $baseUrl . '/' . rawurlencode($index) . '/_doc';

        try {
            $curl = $this->curlFactory->create();
            $curl->setHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);

            $apiKey = $this->config->getLogStreamDirectApiKey();
            if ($apiKey !== '') {
                $curl->addHeader('Authorization', 'ApiKey ' . $apiKey);
            } else {
                $username = $this->config->getLogStreamDirectUsername();
                if ($username !== '') {
                    $curl->setCredentials($username, $this->config->getLogStreamDirectPassword());
                }
            }

            $timeoutMs = $this->config->getLogStreamDirectTimeoutMs();
            $curl->setTimeout((int)max(1, ceil($timeoutMs / 1000)));
            if (defined('CURLOPT_TIMEOUT_MS')) {
                $curl->setOption(CURLOPT_TIMEOUT_MS, $timeoutMs);
            }
            if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
                $curl->setOption(CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
            }

            if (!$this->config->shouldVerifyLogStreamDirectTls()) {
                $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
                $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            }

            $curl->post($endpoint, $encoded);
        } catch (\Throwable $exception) {
            // Fail-open by design: logging transport issues must not impact request handling.
        }
    }

    private function getStreamHandle()
    {
        if (is_resource($this->streamHandle)) {
            return $this->streamHandle;
        }

        $handle = @fopen($this->streamPath, 'ab');
        if ($handle === false) {
            return null;
        }

        $this->streamHandle = $handle;

        return $this->streamHandle;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isAllowedByLevel(array $record): bool
    {
        $minLevel = $this->levelToInt($this->config->getLogStreamMinLevel());
        $recordLevel = $this->extractLevel($record);

        return $recordLevel >= $minLevel;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLevel(array $record): int
    {
        if (isset($record['level']) && is_numeric($record['level'])) {
            return (int)$record['level'];
        }

        if (isset($record['level']) && is_object($record['level'])) {
            $level = $record['level'];
            if (property_exists($level, 'value') && is_numeric($level->value)) {
                return (int)$level->value;
            }
        }

        if (isset($record['level_name'])) {
            return $this->levelToInt((string)$record['level_name']);
        }

        if (isset($record['level']) && is_object($record['level'])) {
            $level = $record['level'];

            if (method_exists($level, 'getName')) {
                return $this->levelToInt((string)$level->getName());
            }

            if (property_exists($level, 'name')) {
                return $this->levelToInt((string)$level->name);
            }
        }

        return 200;
    }

    private function levelToInt(string $level): int
    {
        $normalized = strtolower(trim($level));
        $map = [
            'debug' => 100,
            'info' => 200,
            'notice' => 250,
            'warning' => 300,
            'error' => 400,
            'critical' => 500,
            'alert' => 550,
            'emergency' => 600,
        ];

        return $map[$normalized] ?? 300;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractTimestamp(array $record): string
    {
        if (isset($record['datetime']) && $record['datetime'] instanceof DateTimeInterface) {
            return $record['datetime']->format(DateTimeInterface::ATOM);
        }

        return gmdate('c');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLevelName(array $record): string
    {
        if (isset($record['level_name'])) {
            return strtolower((string)$record['level_name']);
        }

        if (isset($record['level']) && is_object($record['level'])) {
            $level = $record['level'];

            if (method_exists($level, 'getName')) {
                return strtolower((string)$level->getName());
            }

            if (property_exists($level, 'name')) {
                return strtolower((string)$level->name);
            }
        }

        return 'info';
    }
}

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

        $level = $this->extractLevel($record);
        if (!$this->isAllowedByLevel($level)) {
            return;
        }

        $payload = $this->buildPayload($record, $logFile, $level);
        $encoded = $this->encodePayload($payload);
        if ($encoded === null) {
            return;
        }

        if (!$this->config->isDirectLogStreamEnabled()) {
            $this->emitToStderr($encoded);
        } else {
            $this->emitToDirectEndpoint($encoded);
        }
    }

    private function emitToStderr(string $encoded): void
    {
        $stream = $this->getStreamHandle();
        if (!is_resource($stream)) {
            return;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
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

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
            $handle = fopen($this->streamPath, 'ab');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            return null;
        }

        $this->streamHandle = $handle;

        return $this->streamHandle;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isAllowedByLevel(int $recordLevel): bool
    {
        $minLevel = $this->levelToInt($this->config->getLogStreamMinLevel());

        return $recordLevel >= $minLevel;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLevel(array $record): int
    {
        $numericLevel = $this->extractNumericLevel($record);
        if ($numericLevel !== null) {
            return $numericLevel;
        }

        $levelName = $this->extractLevelName($record);
        if ($levelName !== '') {
            return $this->levelToInt($levelName);
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

    /**
     * @param array<string, mixed> $record
     */
    private function buildPayload(array $record, string $logFile, int $level): array
    {
        return [
            '@timestamp' => $this->extractTimestamp($record),
            'message' => $this->extractMessage($record),
            'log.level' => $this->extractLevelName($record),
            'log.level_num' => $level,
            'magento.log_file' => $logFile,
            'magento.channel' => $this->extractChannel($record),
            'context' => $this->extractArrayField($record, 'context'),
            'extra' => $this->extractArrayField($record, 'extra'),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractMessage(array $record): string
    {
        if (!isset($record['message'])) {
            return '';
        }

        return (string)$record['message'];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractChannel(array $record): string
    {
        if (!isset($record['channel'])) {
            return 'main';
        }

        return (string)$record['channel'];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function extractArrayField(array $record, string $key): array
    {
        if (!isset($record[$key]) || !is_array($record[$key])) {
            return [];
        }

        return $record[$key];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractNumericLevel(array $record): ?int
    {
        if (isset($record['level']) && is_numeric($record['level'])) {
            return (int)$record['level'];
        }

        if (!isset($record['level']) || !is_object($record['level'])) {
            return null;
        }

        $level = $record['level'];
        if (property_exists($level, 'value') && is_numeric($level->value)) {
            return (int)$level->value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return null;
        }

        return $encoded;
    }
}

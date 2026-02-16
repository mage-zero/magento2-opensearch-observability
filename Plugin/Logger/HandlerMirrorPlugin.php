<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Plugin\Logger;

use MageZero\OpensearchObservability\Model\Config;
use MageZero\OpensearchObservability\Model\Log\StderrEmitter;

class HandlerMirrorPlugin
{
    private const SYSTEM_HANDLER = 'Magento\\Framework\\Logger\\Handler\\System';
    private const DEBUG_HANDLER = 'Magento\\Framework\\Logger\\Handler\\Debug';
    private const EXCEPTION_HANDLER = 'Magento\\Framework\\Logger\\Handler\\Exception';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StderrEmitter
     */
    private $stderrEmitter;

    public function __construct(
        Config $config,
        StderrEmitter $stderrEmitter
    ) {
        $this->config = $config;
        $this->stderrEmitter = $stderrEmitter;
    }

    /**
     * @param object $subject
     * @param callable $proceed
     * @param mixed $record
     * @return mixed
     */
    public function aroundHandle($subject, callable $proceed, $record)
    {
        $result = $proceed($record);

        if (!$this->config->isLogStreamingEnabled()) {
            return $result;
        }

        $normalizedRecord = $this->normalizeRecord($record);
        if ($normalizedRecord === null) {
            return $result;
        }

        $this->stderrEmitter->emit($normalizedRecord, $this->resolveLogFile($subject));

        return $result;
    }

    /**
     * @param mixed $record
     * @return array<string, mixed>|null
     */
    private function normalizeRecord($record): ?array
    {
        if (is_array($record)) {
            return $record;
        }

        if (!is_object($record)) {
            return null;
        }

        $normalized = [];
        $fields = ['message', 'level', 'level_name', 'channel', 'context', 'extra', 'datetime'];

        foreach ($fields as $field) {
            if (property_exists($record, $field)) {
                try {
                    $normalized[$field] = $record->{$field};
                } catch (\Throwable $exception) {
                    // Skip inaccessible fields.
                }
            }
        }

        return $normalized;
    }

    /**
     * @param object $subject
     */
    private function resolveLogFile($subject): string
    {
        $handlerClass = get_class($subject);

        if ($handlerClass === self::SYSTEM_HANDLER) {
            return 'system.log';
        }

        if ($handlerClass === self::DEBUG_HANDLER) {
            return 'debug.log';
        }

        if ($handlerClass === self::EXCEPTION_HANDLER) {
            return 'exception.log';
        }

        return 'application.log';
    }
}

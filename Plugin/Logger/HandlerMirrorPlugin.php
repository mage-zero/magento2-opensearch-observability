<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Plugin\Logger;

use MageZero\OpensearchObservability\Model\Config;
use MageZero\OpensearchObservability\Model\Log\StderrEmitter;
use Magento\Framework\Logger\Handler\Debug as DebugHandler;
use Magento\Framework\Logger\Handler\Exception as ExceptionHandler;
use Magento\Framework\Logger\Handler\System as SystemHandler;

class HandlerMirrorPlugin
{
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
        $isEnabled = $this->safeIsLogStreamingEnabled();
        if ($isEnabled !== true) {
            return $proceed($record);
        }

        $normalizedRecord = $this->normalizeRecord($record);
        if ($normalizedRecord === null) {
            return $proceed($record);
        }

        if ($this->safeIsDirectLogStreamEnabled()) {
            $result = $proceed($record);
            $this->safeEmit($normalizedRecord, $this->resolveLogFile($subject));
            return $result;
        }

        if ($this->safeEmit($normalizedRecord, $this->resolveLogFile($subject))) {
            return false;
        }

        // Fail-open when stream transport fails so logging never breaks request handling.
        return $proceed($record);
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
        if ($subject instanceof SystemHandler) {
            return 'system.log';
        }

        if ($subject instanceof DebugHandler) {
            return 'debug.log';
        }

        if ($subject instanceof ExceptionHandler) {
            return 'exception.log';
        }

        return 'application.log';
    }

    private function safeIsLogStreamingEnabled(): ?bool
    {
        try {
            return $this->config->isLogStreamingEnabled();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function safeIsDirectLogStreamEnabled(): bool
    {
        try {
            return $this->config->isDirectLogStreamEnabled();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function safeEmit(array $record, string $logFile): bool
    {
        try {
            $this->stderrEmitter->emit($record, $logFile);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}

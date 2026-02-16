<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Plugin\Logger;

use MageZero\OpensearchObservability\Model\Config;
use MageZero\OpensearchObservability\Model\Log\StderrEmitter;
use MageZero\OpensearchObservability\Plugin\Logger\HandlerMirrorPlugin;
use Magento\Framework\Logger\Handler\System;
use PHPUnit\Framework\TestCase;

class HandlerMirrorPluginTest extends TestCase
{
    public function testAroundHandleMirrorsKnownHandlerWithLogFileContext(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(true);

        $emitter = $this->createMock(StderrEmitter::class);
        $emitter->expects($this->once())
            ->method('emit')
            ->with(
                $this->callback(static function (array $record): bool {
                    return isset($record['message']) && $record['message'] === 'A warning';
                }),
                'system.log'
            );

        $plugin = new HandlerMirrorPlugin($config, $emitter);

        $subject = $this->getMockBuilder(System::class)
            ->disableOriginalConstructor()
            ->getMock();

        $result = $plugin->aroundHandle(
            $subject,
            static function ($record) {
                return $record['message'];
            },
            [
                'message' => 'A warning',
                'level' => 300,
                'level_name' => 'WARNING',
                'context' => [],
                'extra' => [],
            ]
        );

        $this->assertSame('A warning', $result);
    }

    public function testAroundHandleSkipsMirroringWhenDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isLogStreamingEnabled')->willReturn(false);

        $emitter = $this->createMock(StderrEmitter::class);
        $emitter->expects($this->never())->method('emit');

        $plugin = new HandlerMirrorPlugin($config, $emitter);

        $result = $plugin->aroundHandle(
            new \stdClass(),
            static function ($record) {
                return $record;
            },
            ['message' => 'ignored']
        );

        $this->assertSame(['message' => 'ignored'], $result);
    }
}

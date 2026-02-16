<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Integration;

use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

class ModuleRegistrationTest extends TestCase
{
    private const MODULE_NAME = 'MageZero_OpensearchObservability';

    public function testModuleIsRegistered(): void
    {
        /** @var ModuleListInterface $moduleList */
        $moduleList = Bootstrap::getObjectManager()->get(ModuleListInterface::class);
        $this->assertTrue($moduleList->has(self::MODULE_NAME));
    }
}

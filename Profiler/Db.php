<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Profiler;

use MageZero\OpensearchObservability\Model\Config as ModuleConfig;
use Magento\Framework\App\ObjectManager;

class Db extends \Magento\Framework\Model\ResourceModel\Db\Profiler
{
    /**
     * @var bool|null
     */
    private $dbSpanEnabled = null;

    /**
     * @param string $queryText
     * @param string|null $queryType
     * @return int|null
     */
    public function queryStart($queryText, $queryType = null)
    {
        $result = parent::queryStart($queryText, $queryType);

        if ($result === null) {
            return null;
        }

        if (!$this->isDbSpanEnabled()) {
            return $result;
        }

        $queryTypeParsed = $this->_parseQueryType($queryText);
        $timerName = $this->_getTimerName($queryTypeParsed);

        $tags = [];
        $typePrefix = '';
        if ($this->_type) {
            $tags['group'] = $this->_type;
            $typePrefix = $this->_type . ':';
        }

        $tags['operation'] = $typePrefix . $queryTypeParsed;
        $tags['statement'] = $queryText;

        if ($this->_host) {
            $tags['host'] = $this->_host;
        }

        \Magento\Framework\Profiler::start($timerName, $tags);

        return $result;
    }

    private function isDbSpanEnabled(): bool
    {
        if ($this->dbSpanEnabled !== null) {
            return $this->dbSpanEnabled;
        }

        try {
            /** @var ModuleConfig $config */
            $config = ObjectManager::getInstance()->get(ModuleConfig::class);
            $this->dbSpanEnabled = $config->isApmEnabled() && $config->isDbProfilerEnabled();
        } catch (\Throwable $exception) {
            $this->dbSpanEnabled = false;
        }

        return $this->dbSpanEnabled;
    }
}

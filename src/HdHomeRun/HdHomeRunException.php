<?php

namespace HdHomeRun;

class HdHomeRunException extends \Exception
{
    /** @var HdHomeRunLib */
    protected $_configInstance = null;

    public function __construct(HdHomeRunLib $configInstance, $message) {
        $this->_configInstance = $configInstance;

        $this->_configInstance->log($message, HdHomeRunLib::LOG_LEVEL_ERROR);

        return parent::__construct($message);
    }

    /**
     * @return HdHomeRunLib
     */
    public function getConfigInstance() {
        return $this->_configInstance;
    }
}
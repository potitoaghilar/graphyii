<?php

namespace app\helpers;

use Exception;

/**
 * Class GraphDatabaseAccessLayerException. Exception to raise when something in GraphDatabaseAccessLayer is not expected
 * @package app\helpers
 */
class GraphDatabaseAccessLayerException extends Exception {

    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

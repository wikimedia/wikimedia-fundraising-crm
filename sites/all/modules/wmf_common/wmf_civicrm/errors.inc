<?php

class WmfException extends Exception
{
    static $error_types = array(
        'CIVI_CONFIG' => array(
            'rollback' => TRUE,
            'fatal' => TRUE,
        ),
        'STOMP_BAD_CONNECTION' => array(
            'rollback' => TRUE,
        ),
        'INVALID_MESSAGE' => array(
            'rollback' => TRUE,
            'reject' => TRUE,
        ),
        'CIVI_REQ_FIELD' => array(
            'rollback' => TRUE,
            'reject' => TRUE,
        ),
        'IMPORT_CONTACT' => array(
            'rollback' => TRUE,
            'fatal' => TRUE,
        ),
        'IMPORT_CONTRIB' => array(
            'rollback' => TRUE,
            'fatal' => TRUE,
        ),
        'IMPORT_SUBSCRIPTION' => array(
            'rollback' => TRUE,
            'fatal' => TRUE,
        ),
        'IMPORT_TAG' => array(
            'rollback' => TRUE,
            'fatal' => TRUE,
        ),
    );

    var $type;

    function __construct($type, $message, $source = null)
    {
        $this->type = $type;
        if (is_array($message)) {
            $message = implode("\n", $message);
        }
        if ($source) {
            $message .= "\n" . print_r($source, TRUE);
        }
        $this->message = "$type $message";
        watchdog('wmf_civicrm', explode("\n", $this->message), NULL, WATCHDOG_ERROR);
        if (function_exists('drush_set_error')) {
            drush_set_error($type, $this->message);
        }
    }

    function getErrorName()
    {
        return $this->type;
    }

    function isRollbackDb()
    {
        return $this->getErrorCharacteristic('rollback', FALSE);
    }

    function isRejectMessage()
    {
        return $this->getErrorCharacteristic('reject', FALSE);
    }

    function isFatal()
    {
        return $this->getErrorCharacteristic('fatal', FALSE);
    }

    protected function getErrorCharacteristic($property, $default)
    {
        $info = self::$error_types[$this->type];
        if (array_key_exists($property, $info)) {
            return $info[$property];
        }
        return $default;
    }
}

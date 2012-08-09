<?php

//TODO hierarchy that encodes what type of rollback is necessary

class WmfException extends Exception
{
    function __construct($type, $message, $source = null)
    {
        if (is_array($message)) {
            $message = implode("\n", $message);
        }
        if ($source) {
            $message .= "\n" . print_r($source, TRUE);
        }
        $message = "$type $message";
        watchdog('wmf_civicrm', explode("\n", $message), NULL, WATCHDOG_ERROR);
        drush_set_error($type, $message);
    }
}

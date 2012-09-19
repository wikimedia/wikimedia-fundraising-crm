<?php

function recurring_globalcollect_error($exception)
{
    watchdog('recurring_globalcollect', $exception->getMessage(), array(), WATCHDOG_ERROR);
    if (function_exists('drush_set_error'))
        drush_set_error($exception->err_code, $exception->getMessage());
    wmf_common_failmail($exception);
}

class RecurringGlobalcollectException extends Exception
{
    var $err_code = 'UNKNOWN';
    var $notes = '';

    function __construct($message, $source = null, $previous = null)
    {
        if ($previous)
        {
            parent::__construct($message, 0, $previous);
        }
        $this->message = "{$this->err_code}: {$this->notes} {$message}";
        $this->source = $source;
    }

    function get_err_code()
    {
        return $this->err_code;
    }
}

class RecurringGlobalcollectCiviConfigException extends RecurringGlobalcollectException
{
    var $err_code = 'CIVI_CONFIG';
    var $notes = '***This contribution has been charged, but was not inserted as a Civi record***';
}

class RecurringGlobalcollectGatewayException extends RecurringGlobalcollectException
{
    var $err_code = 'GLOBALCOLLECT';
}

class RecurringGlobalcollectCiviInsertException extends RecurringGlobalcollectException
{
    var $err_code = 'CIVI_INSERT';
}

class RecurringGlobalcollectInternalException extends RecurringGlobalcollectException
{
    var $err_code = 'RG';
}

class RecurringGlobalcollectCouldNotLockException extends RecurringGlobalcollectException
{
    var $err_code = 'RG_COULDNOTLOCK';
}

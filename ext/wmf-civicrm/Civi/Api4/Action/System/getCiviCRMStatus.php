<?php
namespace Civi\Api4\Action\System;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class getCiviCRMStatus extends AbstractAction
{
    /**
     * This action is only run to check Civi's online status
     */
    public function _run(Result $result)
    {
        $result[] = [
            'success' => True,
        ];
    }
}
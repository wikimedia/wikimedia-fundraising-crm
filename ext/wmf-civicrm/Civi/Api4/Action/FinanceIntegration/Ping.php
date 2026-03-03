<?php
namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\FinanceIntegration\Connection;

/**
 * @method $this setIsEndowment(bool $isEndowment)
 * @method $this setIsStaging(bool $isEndowment)
 */
class Ping extends AbstractAction {

  /**
   * Is Endowment
   *
   * Is the connection we want to use the Endowment connection.
   *
   * (ie which Sage instance are we connecting to)
   *
   * @var bool
   */
  protected bool $isEndowment = FALSE;

  /**
   * Are we connecting to the staging instance?
   *
   * @var bool
   */
  protected bool $isStaging = TRUE;
  public function _run(Result $result) {
    $connection = new Connection($this->isEndowment ? 'endowment': 'wmf', $this->isStaging);
    $outcome = $connection->getApiClient();
    $result[] = $outcome['headers'];
  }
}

<?php

namespace Civi\Api4\Action\Base62;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use SmashPig\Core\Helpers\Base62Helper;

/**
 * Check whether a reconciliation_id and transaction_id refer to the same
 * underlying gateway transaction.
 *
 * Both reconciliation_id and transaction_id are required.
 *
 * @method $this setReconciliationId(?string $reconciliationId)
 * @method $this setTransactionId(?string $transactionId)
 * @method string|null getReconciliationId()
 * @method string|null getTransactionId()
 */
class Validate extends AbstractAction {

  /**
   * Base62-encoded payment orchestrator reconciliation ID.
   *
   * @var string|null
   */
  protected ?string $reconciliationId = NULL;

  /**
   * Gateway transaction ID (UUID) to check the reconciliation_id against.
   *
   * @var string|null
   */
  protected ?string $transactionId = NULL;

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if (!$this->reconciliationId || !$this->transactionId) {
      throw new \CRM_Core_Exception('Base62.validate requires both reconciliation_id and transaction_id.');
    }
    $isValid = strcasecmp(Base62Helper::toUuid($this->reconciliationId), $this->transactionId) === 0;
    $result[] = [
      'reconciliation_id' => $this->reconciliationId,
      'transaction_id' => $this->transactionId,
      'is_valid' => $isValid,
    ];
  }

}

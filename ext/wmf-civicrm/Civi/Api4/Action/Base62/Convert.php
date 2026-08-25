<?php

namespace Civi\Api4\Action\Base62;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use SmashPig\Core\Helpers\Base62Helper;

/**
 * Convert a reconciliation_id to a transaction_id, or vice versa.
 *
 * Only one of reconciliation_id / transaction_id is required - the other is
 * derived from it. If both are given, reconciliation_id takes precedence.
 *
 * @method $this setReconciliationId(?string $reconciliationId)
 * @method $this setTransactionId(?string $transactionId)
 * @method string|null getReconciliationId()
 * @method string|null getTransactionId()
 */
class Convert extends AbstractAction {

  /**
   * Base62-encoded payment orchestrator reconciliation ID.
   *
   * @var string|null
   */
  protected ?string $reconciliationId = NULL;

  /**
   * Gateway transaction ID (UUID) encoded by the reconciliation_id.
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
    if ($this->reconciliationId) {
      $result[] = [
        'reconciliation_id' => $this->reconciliationId,
        'transaction_id' => Base62Helper::toUuid($this->reconciliationId),
      ];
      return;
    }
    if ($this->transactionId) {
      $result[] = [
        'reconciliation_id' => Base62Helper::fromUuid($this->transactionId),
        'transaction_id' => $this->transactionId,
      ];
      return;
    }
    throw new \CRM_Core_Exception('Base62.convert requires either reconciliation_id or transaction_id.');
  }

}

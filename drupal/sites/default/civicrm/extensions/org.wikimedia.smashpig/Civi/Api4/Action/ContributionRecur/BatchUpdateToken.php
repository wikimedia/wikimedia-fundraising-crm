<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class BatchUpdateToken.
 *
 * Retrieves a batch of N recurring payments needing token updates and calls
 * UpdateToken on each of them.
 *
 * @method $this setProcessorName(string $processorName) Set processor name.
 * @method string getProcessorName() Get processor name.
 * @method $this setBatch(int $batch) Set batch size.
 * @method int getBatch() Get batch size.
 */
class BatchUpdateToken extends AbstractAction {

  protected $batch = 0;

  /**
   * @required
   */
  protected $processorName;

  public function _run(Result $result) {
    $recurBatch = ContributionRecur::get(FALSE)
      ->addWhere('payment_processor_id.name', '=', $this->processorName)
      ->addWhere('invoice_id', 'IS NULL')
      ->addWhere('payment_token_id.token', 'IS NOT EMPTY')
      // Limit to In Progress status
      ->addWhere('contribution_status_id', '=', 5)
      ->setSelect([
        'id',
        'invoice_id',
        'is_test',
        'payment_token_id',
        'payment_token_id.token',
        'payment_processor_id.name'
      ])
      ->setLimit($this->getBatch())
      ->execute();
    $result->rowCount = $recurBatch->rowCount;
    foreach ($recurBatch as $recurInfo) {
      $updateAction = new UpdateToken('ContributionRecur', 'UpdateToken');
      $updateAction->setProcessorName($this->getProcessorName())
        ->setContributionRecurId($recurInfo['id'])
        ->setRecurInfo($recurInfo);
      $singleResult = $updateAction->execute();
      \Civi::Log('wmf')->info(
        "Tokenized contribution_recur {$recurInfo['id']} with result: " .
        json_encode($singleResult->first())
      );
      $result[] = $singleResult->first();
    }
  }
}

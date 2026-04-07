<?php

namespace Civi\Api4\Action\Contribution;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;

/**
 * Refunds a transaction and potentially marks it as fraud
 *
 * @method setProcessorName(string $processorName)
 * @method setAmount(float $amount)
 * @method setTransactionID(string $transactionID)
 * @method setContributionID(int $contributionID)
 * @method setIsFraud(bool $isFraud)
 */
class RefundAndMarkIfFraud extends AbstractAction {

  protected $processorName;
  protected $amount;
  protected $transactionID;
  protected $contributionID;
  protected $isFraud;

  public function _run(Result $result) {
    $processor = PaymentProcessor::get(FALSE)
      ->addWhere('name', '=', $this->processorName)
      ->addWhere('is_test', '=', FALSE)
      ->execute()->first();
    if (!$processor) {
      throw new \RuntimeException('No processor found for ' . $this->processorName);
    }
    $refundResult = PaymentProcessor::refund(FALSE)
      ->setPaymentProcessorID($processor['id'])
      ->setAmountToRefund($this->amount)
      ->setTransactionID($this->transactionID)
      ->execute();
    if ($refundResult['refund_status'] === 'Completed') {
      $updateCall = Contribution::update(FALSE)
        ->addWhere('id', '=', $this->contributionID)
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->addValue('contribution_status_id:name', 'Refunded');
      if ($this->isFraud) {
        $updateCall->addValue('cancel_reason', 'fraud');
      }
      if ($refundResult['processor_id']) {
        if ($this->processorName === 'gravy') {
          $fieldName = 'payment_orchestrator_reversal_id';
        } else {
          $fieldName = 'backend_processor_reversal_id';
        }
        $updateCall->addValue("contribution_extra.$fieldName", $refundResult['processor_id']);
      }
      $updateCall->execute();
    }
    $result[] = $refundResult;
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['refund contributions'];
  }
}

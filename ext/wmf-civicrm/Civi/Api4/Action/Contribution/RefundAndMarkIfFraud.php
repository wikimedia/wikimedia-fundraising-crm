<?php

namespace Civi\Api4\Action\Contribution;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
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

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function _run(Result $result): void {
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
      $this->markContributionRefunded($refundResult['processor_id']);
      $this->addRefundActivity($refundResult['processor_id']);
    }
    $result[] = $refundResult;
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['refund contributions'];
  }

  /**
   * @param string|null $processorRefundID
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function markContributionRefunded(?string $processorRefundID): void {
    $updateCall = Contribution::update(FALSE)
      ->addWhere('id', '=', $this->contributionID)
      ->addValue('cancel_date', date('Y-m-d H:i:s'))
      ->addValue('contribution_status_id:name', 'Refunded');
    if ($this->isFraud) {
      $updateCall->addValue('cancel_reason', 'fraud');
    }
    if ($processorRefundID) {
      if ($this->processorName === 'gravy') {
        $fieldName = 'payment_orchestrator_reversal_id';
      }
      else {
        $fieldName = 'backend_processor_reversal_id';
      }
      $updateCall->addValue("contribution_extra.$fieldName", $processorRefundID);
    }
    $updateCall->execute();
  }

  protected function addRefundActivity(?string $processorRefundID): void {
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $this->contributionID)
      ->setSelect(['contact_id', 'invoice_id'])
      ->execute()->first();
    $contactID = $contribution['contact_id'];
    $details = "Contribution with merchant reference {$contribution['invoice_id']} " .
      "and $this->processorName transaction ID $this->transactionID was refunded.";
    if ($processorRefundID) {
      $details .= " The gateway refund ID was $processorRefundID.";
    }
    if ($this->isFraud) {
      $details .= " The transaction was also marked as fraudulent.";
    }
    Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Refund')
      ->addValue('source_record_id', $this->contributionID)
      ->addValue('source_contact_id', \CRM_Core_Session::getLoggedInContactID() ?? $contactID)
      ->addValue('target_contact_id', $contactID)
      ->addValue('subject', 'Contribution was refunded')
      ->addValue('details', $details)
      ->execute();
  }
}

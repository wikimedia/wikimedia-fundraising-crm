<?php

use Civi\Api4\Contribution;
use Civi\Api4\PaymentProcessor;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Wmf_Form_RefundContribution extends CRM_Core_Form {

  /**
   * Build basic form.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    $contributionID = CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this);
    $contribution = Contribution::get(FALSE)
      ->addSelect('*')
      ->addSelect('contribution_status_id:name')
      ->addSelect('contribution_extra.*')
      ->addWhere('id', '=', $contributionID)
      ->execute()->first();
    $status = $contribution['contribution_status_id:name'];
    if ($status !== 'Completed') {
      $message = ($status === 'Refunded')
        ? "Contribution is refunded"
        : "Unable to refund contribution with status $status";
      $this->assign('no_go_reason', $message);
      return;
    }
    $amount = $contribution['contribution_extra.original_amount'];
    $currency = $contribution['contribution_extra.original_currency'];
    $processorName = $contribution['contribution_extra.gateway'];
    $this->assign('amount', $amount);
    $this->assign('currency', $currency);
    $this->assign('processor', $processorName);
    $this->assign('receive_date', $contribution['receive_date']);
    $this->assign('trxn_id', $contribution['trxn_id']);
    $this->add('checkbox', 'is_fraud', 'Mark as fraudulent');
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => 'Submit refund to processor',
        'isDefault' => TRUE,
      ],
    ]);
    parent::buildQuickForm();
  }

  /**
   * Submit form.
   */
  public function postProcess(): void {
    $vars = $this->getTemplateVars();
    if (isset($vars['no_go_reason'])) {
      CRM_Core_Session::setStatus($vars['no_go_reason']);
      return;
    }
    $processor = PaymentProcessor::get(FALSE)
      ->addWhere('name', '=', $vars['processor'])
      ->addWhere('is_test', '=', FALSE)
      ->execute()->first();
    $result = PaymentProcessor::Refund(FALSE)
      ->setPaymentProcessorID($processor['id'])
      ->setAmountToRefund($vars['amount'])
      ->setTransactionID($vars['trxn_id'])
      ->execute();
    if ($result['refund_status'] === 'Completed') {
      $updateCall = Contribution::update(FALSE)
        ->addWhere('id', '=', CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this))
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->addValue('contribution_status_id:name', 'Refunded');
      if ($this->getSubmittedValue('is_fraud')) {
        $updateCall->addValue('cancel_reason', 'fraud');
      }
      if ($result['processor_id']) {
        if ($vars['processor'] === 'gravy') {
          $fieldName = 'payment_orchestrator_reversal_id';
        } else {
          $fieldName = 'backend_processor_reversal_id';
        }
        $updateCall->addValue("contribution_extra.$fieldName", $result['processor_id']);
      }
      $updateCall->execute();
    }
    CRM_Core_Session::setStatus('Refund status: ' . $result['refund_status']);
  }

}

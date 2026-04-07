<?php

use Civi\Api4\Contribution;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Wmf_Form_RefundContribution extends CRM_Contribute_Form_Task {

  const MAX_REFUNDS_TO_PROCESS_SYNCHRONOUSLY = 5;

  /**
   * Build basic form.
   *
   * @throws CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    if (!empty($this->_submitValues['id'])) {
      // Initial load from SearchKit, or submitting this form.
      // Note that under SearchKit $this->_contributionIds contains ALL the
      // IDs returned in the search, not the specific IDs selected for the action.
      $ids = explode(',', $this->_submitValues['id']);
    } else {
      // Loaded from individual contact contribution tab or from old contribution search
      $ids = $this->_contributionIds;
    }

    $contributions = Contribution::get(FALSE)
      ->addSelect('*')
      ->addSelect('contribution_status_id:name')
      ->addSelect('contact_id.display_name')
      ->addSelect('contribution_extra.*')
      ->addWhere('id', 'IN', $ids)
      ->execute();
    $toRefund = [];
    $notToRefund = [];
    foreach($contributions as $contribution) {
      $status = $contribution['contribution_status_id:name'];
      // Make a few things with dots available in Smarty
      $contribution['display_name'] = $contribution['contact_id.display_name'];
      $contribution['original_amount'] = $contribution['contribution_extra.original_amount'];
      $contribution['original_currency'] = $contribution['contribution_extra.original_currency'];

      if ($status === 'Completed' && $this->processorCanRefund($contribution)) {
        $toRefund[] = $contribution;
      }
      else {
        $notToRefund[] = $contribution;
      }
    }
    $numberToRefund = count($toRefund);
    if ($numberToRefund === 0) {
      $this->assign('no_go_reason', 'No selected contributions can be refunded');
      return;
    }
    if ($numberToRefund > self::MAX_REFUNDS_TO_PROCESS_SYNCHRONOUSLY) {
      $this->assign('async_message', 'Too many to refund synchronously. Refunds will be processed in the background.');
    }
    $this->assign('to_refund', $toRefund);
    $this->assign('not_to_refund', $notToRefund);
    $this->add('checkbox', 'is_fraud', 'Mark as fraudulent');
    $this->add('hidden', 'id', implode(',', $ids));
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => 'Submit refund(s)',
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
    $isFraud = $this->getSubmittedValue('is_fraud') ?? FALSE;
    $numberToProcess = count($vars['to_refund']);
    $results = [];
    if ($numberToProcess > self::MAX_REFUNDS_TO_PROCESS_SYNCHRONOUSLY) {
      $queue = \Civi::queue('refund', [
        'type' => 'Sql',
        'runner' => 'task',
        'retry_limit' => 3,
        'retry_interval' => 20,
        'error' => 'abort',
      ]);
      foreach ($vars['to_refund'] as $contribution) {
        $this->refundViaQueue($queue, $contribution, $isFraud);
        $results[$contribution['id']] = [
          'refund_status' => 'Queued',
          'trxn_id' => $contribution['trxn_id'],
        ];
      }
      CRM_Core_Session::setStatus("Processing $numberToProcess refunds in the background");
    }
    else {
      $successCount = $failureCount = 0;
      foreach ($vars['to_refund'] as $contribution) {
        try {
          $results[$contribution['id']] = Contribution::refundAndMarkIfFraud(FALSE)
            ->setContributionID($contribution['id'])
            ->setProcessorName($contribution['contribution_extra.gateway'])
            ->setAmount($contribution['contribution_extra.original_amount'])
            ->setTransactionID($contribution['trxn_id'])
            ->setIsFraud($isFraud)
            ->execute()->first();
          $results[$contribution['id']]['trxn_id'] = $contribution['trxn_id'];
        }
        catch (\Exception $e) {
          $results[$contribution['id']] = [
            'refund_status' => 'Failed',
            'trxn_id' => $contribution['trxn_id'],
            'error' => $e->getMessage(),
          ];
        }
        if ($results[$contribution['id']]['refund_status'] === 'Completed') {
          $successCount++;
        } else {
          $failureCount++;
        }
      }
      CRM_Core_Session::setStatus("$successCount succeeded, $failureCount failed.");
    }
    $this->assign('results', $results);
  }

  protected function refundViaQueue($queue, $contribution, $isFraud) {
    $refundParameters = [
      'contributionID' => $contribution['id'],
      'processorName' => $contribution['contribution_extra.gateway'],
      'amount' => $contribution['contribution_extra.original_amount'],
      'transactionID' => $contribution['trxn_id'],
      'isFraud' => $isFraud
    ];
    $queue->createItem(new \CRM_Queue_Task(
      'civicrm_api4_queue',
      ['Contribution', 'refundAndMarkIfFraud', $refundParameters],
      'Refund contribution ' . $contribution['trxn_id'],
    ), ['weight' => 100]);
  }

  protected function processorCanRefund($contribution) {
    if (empty($contribution['contribution_extra.gateway'])) {
      return FALSE;
    }
    try {
      $processor = \Civi\Payment\System::singleton()->getByName(
        $contribution['contribution_extra.gateway'],
        FALSE
      );
      return $processor !== NULL && $processor->supportsRefund();
    }
    catch (CRM_Core_Exception) {
      return FALSE;
    }
  }
}

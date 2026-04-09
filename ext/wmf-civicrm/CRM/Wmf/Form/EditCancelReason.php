<?php

use Civi\WMFHelper\ContributionRecur;

/**
 * Form to edit the cancellation reason on a cancelled recurring contribution.
 */
class CRM_Wmf_Form_EditCancelReason extends CRM_Contribute_Form_ContributionRecur {

  public function preProcess(): void {
    $this->getContributionRecurID();
    $this->setTitle(ts('Edit Cancellation Reason'));
  }

  public function setDefaultValues(): array {
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('cancel_reason')
      ->addWhere('id', '=', $this->getContributionRecurID())
      ->execute()->first();
    return ['cancel_reason' => $recur['cancel_reason']];
  }

  public function buildQuickForm(): void {
    $reasons = ContributionRecur::getDonorCancelReasons();
    $this->addSelect('cancel_reason', [
      'label' => ts('Cancellation Reason'),
      'placeholder' => ts('- select reason -'),
      'options' => array_combine($reasons, $reasons),
    ], TRUE);
    $this->addButtons([
      ['type' => 'submit', 'name' => ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => ts('Cancel')],
    ]);
  }

  public function postProcess(): void {
    \Civi\Api4\ContributionRecur::update(FALSE)
      ->addValue('cancel_reason', $this->getSubmittedValue('cancel_reason'))
      ->addWhere('id', '=', $this->getContributionRecurID())
      ->execute();
  }
}
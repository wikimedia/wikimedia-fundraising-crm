<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\GatewayAccount;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Ensures that read only fields we want to be available for import are available.
 */
class ImportReadOnlySpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $fieldNames = [
      'contribution_extra.gateway_txn_id',
      'contribution_extra.gateway',
      'contribution_extra.gateway_account',
      'contribution_extra.original_amount',
      'contribution_extra.original_currency',
      'contribution_extra.scheme_fee',
      'contribution_extra.backend_processor',
      'contribution_extra.backend_processor_txn_id',
      'contribution_extra.payment_orchestrator_reconciliation_id',
      'contribution_settlement.settlement_batch_reference',
      'contribution_settlement.settlement_batch_reversal_reference',
      'contribution_settlement.settlement_currency',
      'contribution_settlement.settlement_date',
      'contribution_settlement.settled_donation_amount',
      'contribution_settlement.settled_fee_amount',
      'contribution_settlement.settled_reversal_amount',
      'contribution_settlement.settled_fee_reversal_amount',
    ];
    foreach ($fieldNames as $fieldName) {
      $field = $spec->getFieldByName($fieldName);
      $usage = $field->getUsage();
      $usage[] = 'import';
      $field->setUsage($usage);
      // These fields are marked is_view (readonly) since they're normally
      // code-managed - but it's appropriate to manually enter them when
      // importing / batch data editing (see AbstractRunAction::getEditableInfo()),
      // readonly not enforced on write anyway, so this only affects
      // whether a UI shows an editable input, not what can be saved.
      if ($spec->getAction() === 'create') {
        $field->setReadonly(FALSE);
      }
    }
    // Offer known gateways/gateway accounts as dropdowns rather than free
    // text, to help catch typos at entry rather than at validate/import
    // time - SearchKit's inline-edit renders a select as soon as a field
    // has options (see crmSearchInputVal.component.js), no html_type
    // change needed.
    if ($spec->getAction() === 'create') {
      // contribution_extra.gateway itself is left without options - it's
      // also populated by queue message processing outside imports
      // (donations/recurring), where values aren't limited to this list.
      // Picking a specific account (rather than a plain gateway) is what
      // lets handleSettlementBatch() derive the gateway and everything
      // else in one go.
      $gatewayAccountField = $spec->getFieldByName('contribution_extra.gateway_account');
      $accounts = (array) GatewayAccount::get(FALSE)
        ->addSelect('name', 'label')
        ->execute();
      $accountOptions = [];
      foreach ($accounts as $account) {
        $accountOptions[] = ['id' => $account['name'], 'label' => $account['label']];
      }
      $gatewayAccountField->setOptions($accountOptions);
    }
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool|void
   */
  public function applies($entity, $action) {
    return $entity === 'Contribution' && in_array($action, ['save', 'create', 'update'], TRUE);
  }
}

<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Ensures that read only fields we want to be available for import are available.
 */
class ImportReadOnlySpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $fieldNames = [
      'contribution_extra.gateway_txn_id',
      'contribution_extra.gateway',
      'contribution_extra.original_amount',
      'contribution_extra.original_currency',
      'contribution_extra.scheme_fee',
      'contribution_extra.backend_processor',
      'contribution_extra.backend_processor_txn_id',
      'contribution_extra.payment_orchestrator_reconciliation_id',
    ];
    foreach ($fieldNames as $fieldName) {
      $field = $spec->getFieldByName($fieldName);
      $usage = $field->getUsage();
      $usage[] = 'import';
      $field->setUsage($usage);
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

<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Adds a calculated field flagging batches where the settled donation and
 * fee amounts don't add up to the settled net amount - used to flag/gate
 * the Manual Batches screen rather than requiring the net amount to simply
 * be non-empty.
 *
 * @service
 * @internal
 */
class BatchNetAmountMismatchSpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $field = new FieldSpec('batch_data.net_amount_mismatch', $spec->getEntity(), 'Boolean');
    $field->setLabel(ts('Net Amount Mismatch'))
      ->setTitle(ts('Net Amount Mismatch'))
      ->setDataType('Boolean')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'renderMismatchSql'])
      ->setName('batch_data.net_amount_mismatch')
      ->setDescription('TRUE if settled donation + fee amounts do not equal the settled net amount');
    $spec->addFieldSpec($field);
  }

  public function applies(string $entity, string $action): bool {
    return $entity === 'Batch' && $action === 'get';
  }

  public static function renderMismatchSql(array $field, Api4SelectQuery $query): string {
    $donationField = $query->getFieldSibling($field, 'batch_data.settled_donation_amount');
    $feeField = $query->getFieldSibling($field, 'batch_data.settled_fee_amount');
    $netField = $query->getFieldSibling($field, 'batch_data.settled_net_amount');
    return "ROUND(COALESCE({$donationField['sql_name']}, 0) + COALESCE({$feeField['sql_name']}, 0), 2)
      <> ROUND(COALESCE({$netField['sql_name']}, 0), 2)";
  }

}

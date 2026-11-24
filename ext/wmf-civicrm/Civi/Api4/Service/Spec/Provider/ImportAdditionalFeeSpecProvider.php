<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Adds an import-only pseudo field for a second fee column (e.g. Benevity's Merchant Fee).
 *
 * The import hook adds it to fee_amount and unsets it - it is never saved.
 *
 * @see \Civi\WMFHook\Import::doBenevityWrangling()
 */
class ImportAdditionalFeeSpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $field = new FieldSpec('additional_fee_amount', $spec->getEntity(), 'Money');
    $field->setTitle(ts('Additional Fee Amount'))
      ->setDescription(ts('Added to the fee amount on import, where the fee is split over 2 columns'))
      ->setInputType('Text')
      ->setUsage(['import']);
    $spec->addFieldSpec($field);
  }

  public function applies($entity, $action): bool {
    return $entity === 'Contribution' && in_array($action, ['save', 'create', 'update'], TRUE);
  }

}

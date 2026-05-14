<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

class WMFDonorSpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec) {
    $field = new FieldSpec('all_funds_last_donation_date', $spec->getEntity(), 'datetime');
    $field->setLabel('Last donation date')
      ->setDescription('Last donation date, endowment and foundation');
    $spec->addFieldSpec($field);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool|void
   */
  public function applies($entity, $action) {
    return $entity === 'WMFDonor' && $action === 'get';
  }
}

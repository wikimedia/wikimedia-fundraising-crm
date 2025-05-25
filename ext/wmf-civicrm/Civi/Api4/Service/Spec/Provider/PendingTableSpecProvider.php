<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

class PendingTableSpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec) {
    $field = new FieldSpec('gateway', $spec->getEntity(), 'string');
    $field->setLabel('Gateway')
      ->setDescription('SmashPig code for gateway whose pending messages we should resolve')
      ->setRequired(TRUE);
    $spec->addFieldSpec($field);
    $field = new FieldSpec('minimumAge', $spec->getEntity(), 'integer');
    $field->setLabel('Minimum Age (minutes)')
      ->setDescription(
        'Stop processing when all remaining pending transactions are newer than N minutes old'
      )->setDefaultValue(30); // TODO: settings for defaults
    $spec->addFieldSpec($field);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool|void
   */
  public function applies($entity, $action) {
    return $entity === 'PendingTable' && $action === 'consume';
  }
}

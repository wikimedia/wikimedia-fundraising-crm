<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Common API parameters for Queue/Table Consumer calls
 */
class ConsumerSpecProvider implements Generic\SpecProviderInterface {

  /**
   * When implementing a new queue consumer as an API4 call, just add your
   * pseudo-entity here to automatically get batch and time limit parameter
   * titles and descriptions
   * @var string[]
   */
  protected static $supportedPseudoEntities = [
    'PendingTable'
  ];

  public function modifySpec(RequestSpec $spec) {
    $field = new FieldSpec('timeLimit', $spec->getEntity(), 'integer');
    $field->setTitle('Time limit (seconds)')
      ->setDescription('Consumer will attempt to limit execution to this many seconds');
    $spec->addFieldSpec($field);
    $field = new FieldSpec('batch', $spec->getEntity(), 'integer');
    $field->setTitle('Batch size')
      ->setDescription('Consumer will stop after processing this many items');
    $spec->addFieldSpec($field);
    // TODO: per-entity settings for default values
  }

  public function applies($entity, $action) {
    return in_array($entity, self::$supportedPseudoEntities) && $action === 'consume';
  }
}

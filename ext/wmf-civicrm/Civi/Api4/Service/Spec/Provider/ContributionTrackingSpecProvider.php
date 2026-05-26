<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Exposes `contribution_tracking` as an FK field on Contribution so SearchKit
 * surfaces it as an implicit join target. The actual SQL join is supplied by
 * ContributionSchemaMapSubscriber (alias must match).
 *
 * @service
 * @internal
 */
class ContributionTrackingSpecProvider extends \Civi\Core\Service\AutoService implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    $field = new FieldSpec('contribution_tracking', 'Contribution', 'Integer');
    $field->setLabel(ts('Contribution Tracking'))
      ->setTitle(ts('Contribution Tracking ID'))
      ->setColumnName('id')
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->setFkEntity('ContributionTracking')
      ->setSqlRenderer(['\Civi\Api4\Service\Schema\Joiner', 'getExtraJoinSql']);
    $spec->addFieldSpec($field);
  }

  public function applies($entity, $action): bool {
    return $entity === 'Contribution' && $action === 'get';
  }

}
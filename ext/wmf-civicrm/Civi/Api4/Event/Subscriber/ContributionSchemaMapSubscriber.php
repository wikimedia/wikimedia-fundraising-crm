<?php

namespace Civi\Api4\Event\Subscriber;

use Civi\Api4\Event\SchemaMapBuildEvent;
use Civi\Api4\Service\Schema\Joinable\Joinable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds an implicit one-to-one join from Contribution to ContributionTracking.
 *
 * Together with ContributionTrackingSpecProvider this exposes fields like
 * `contribution_tracking.utm_source` directly on Contribution in SearchKit
 * and APIv4 queries, without requiring an explicit join.
 *
 * @service civi.wmf.contributionSchema
 */
class ContributionSchemaMapSubscriber extends \Civi\Core\Service\AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      'api.schema_map.build' => 'onSchemaBuild',
    ];
  }

  public function onSchemaBuild(SchemaMapBuildEvent $event): void {
    $link = new Joinable('civicrm_contribution_tracking', 'contribution_id', 'contribution_tracking');
    $link->setBaseTable('civicrm_contribution');
    $link->setJoinType(Joinable::JOIN_TYPE_ONE_TO_ONE);
    $event->getSchemaMap()->getTableByName('civicrm_contribution')->addTableLink('id', $link);
  }

}
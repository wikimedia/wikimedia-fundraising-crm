<?php

namespace Civi\WMFHook;

use Civi\Afform\Event\AfformSubmitEvent;

class AddAppealForm {

  /**
   * Use the entered label as the appeal's name and value too.
   */
  public static function preprocess(AfformSubmitEvent $event): void {
    if ($event->getAfform()['name'] !== 'afformAddAppeal') {
      return;
    }
    $records = $event->getRecords();
    foreach ($records as &$record) {
      $appeal = trim($record['fields']['label']);
      $record['fields']['label'] = $record['fields']['name'] = $record['fields']['value'] = $appeal;
    }
    $event->setRecords($records);
  }

}

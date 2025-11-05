<?php

namespace Civi\Helper;

class FailureEmail {
  public static function sendViaQueue(int $contactID, int $contributionRecurID) {
    $queueName = 'email';
    $queue = \Civi::queue($queueName, [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 3,
      'retry_interval' => 20,
      'error' => 'abort',
    ]);
    $queue->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
      [
        'FailureEmail',
        'send',
        [
          'checkPermissions' => FALSE,
          'contactID' => $contactID,
          'contributionRecurID' => $contributionRecurID,
        ],
      ],
      'Send recurring failure email'
    ), ['weight' => 100]);
  }
}

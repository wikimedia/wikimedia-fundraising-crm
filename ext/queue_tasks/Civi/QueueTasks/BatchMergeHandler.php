<?php

namespace Civi\QueueTasks;

use Civi\Api4\Queue;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @service civi.queue.my_stuff
 */
class BatchMergeHandler extends AutoService implements EventSubscriberInterface {

  use \CRM_Queue_BasicHandlerTrait;

  public static function getSubscribedEvents(): array {
    return ['&hook_civicrm_queueRun_batch_merge' => 'runBatch'];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function runItem($item, \CRM_Queue_Queue $queue): void {
    $data = (array) $item->data;

    // Calculate batch parameters & run
    $startDateTime = $data['start_timestamp'];
    $endTimeStamp = strtotime($data['increment'], strtotime($data['start_timestamp']));
    if ($endTimeStamp > time()) {
      // Let's set the end time stamp to 4 minutes ago - reduce the
      // risk of deduping someone who is actively being worked on.
      $endTimeStamp = time() - 240;
    }
    $endDateTime = date('Y-m-d H:i:s', $endTimeStamp);
    $limit = (int) $data["batch_limit"];
    $minimumContactID = $data['min_contact_id'] ?? 0;
    // check count.
    $result = \CRM_Core_DAO::executeQuery(
      "SELECT count(*) as count, MAX(id) as max_contact_id FROM
      civicrm_contact WHERE modified_date BETWEEN '{$startDateTime}' AND '{$endDateTime}'
      AND id > $minimumContactID
      ORDER BY id
      LIMIT $limit
      "
    )->fetchAll()[0];

    $criteria = ['where' => [['modified_date', 'BETWEEN', [$startDateTime, $endDateTime]]]];
    $isLimitApplied = ($result['count'] >= $limit);
    if ($isLimitApplied) {
      // We need to assume there are more based on just date range
      $criteria['where'][] = ['id', 'BETWEEN', [$result['min_contact_id'], $result['max_contact_id']]];
    }
    \Civi::log('batch_merge')->info('deduping {limit} contacts from date {start_date} to date {to date}', [
      'limit' => $limit,
      'start_date' => $startDateTime,
      'to_date' => $endDateTime,
    ]);

    $mergeParams = [
      'criteria' => $criteria,
      // Use a zero limit as we have already calculated limits
      // per https://github.com/civicrm/civicrm-core/pull/15185
      'search_limit' => 0,
    ];

    if ($data['group_id']) {
      $mergeParams['gid'] = $data['group_id'];
    }
    if ($data['rule_group_id']) {
      $mergeParams['rule_group_id'] = $data['rule_group_id'];
    }

    $result = civicrm_api3('Job', 'process_batch_merge', $mergeParams);
    /** @noinspection PhpUndefinedMethodInspection */
    Queue::addDedupeTask(FALSE)
      ->setStartDateTime($isLimitApplied ? $startDateTime : $endDateTime)
      ->setGroupID($data['group_id'] ?? NULL)
      ->setRuleGroupID($data['rule_group_id'] ?? NULL)
      ->setMinimumContactID($isLimitApplied ? $result['max_contact_id'] : NULL)
      ->setIncrement($data['increment'])
      ->setBatchLimit($data['batch_limit'])
      ->execute();
  }

}

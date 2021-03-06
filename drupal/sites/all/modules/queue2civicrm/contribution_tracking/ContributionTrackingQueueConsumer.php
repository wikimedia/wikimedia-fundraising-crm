<?php

namespace queue2civicrm\contribution_tracking;

use \Civi\WMFException\ContributionTrackingDataValidationException;
use wmf_common\WmfQueueConsumer;

class ContributionTrackingQueueConsumer extends WmfQueueConsumer {

  /**
   * Normalise the queue message and insert into the contribution tracking tbl
   *
   * @param array $message contribution-tracking queue message
   *
   * @throws \Civi\WMFException\ContributionTrackingDataValidationException
   */
  public function processMessage($message) {
    if (empty($message['id'])) {
      $error = "missing essential contribution tracking ID. Dropping message on floor." . json_encode($message);
      throw new ContributionTrackingDataValidationException($error);
    }

    watchdog(
      'contribution-tracking',
      "Beginning processing of contribution-tracking message {$message['id']}: " . json_encode($message),
      [],
      WATCHDOG_INFO
    );

    // pick out the fields we want and ignore anything else (e.g. source_* fields)
    $ctData = array_filter($message, function ($key) {
      return in_array($key, [
        'id',
        'contribution_id',
        'note',
        'referrer',
        'anonymous',
        'form_amount',
        'payments_form',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_key',
        'language',
        'country',
        'ts',
      ]);
    }, ARRAY_FILTER_USE_KEY);

    $ctData = $this->truncateFields($ctData);
    $this->persistContributionTrackingData($ctData);

    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $ContributionTrackingStatsCollector->recordContributionTrackingRecord();
  }

  /**
   * Insert or update a contribution tracking entry.
   *
   * @param array $ctData data to be written to contribution tracking tbl
   *
   * @throws \Exception
   */
  protected function persistContributionTrackingData($ctData) {
    $checkSql = "
SELECT id, contribution_id FROM contribution_tracking
WHERE id = :ct_id
LIMIT 1";

    $checkResult = db_query($checkSql, [
      ':ct_id' => $ctData['id'],
    ]);

    if ($checkResult->rowCount() > 0) {
      $existingRow = $checkResult->fetchAssoc();
      if (!empty($existingRow['contribution_id'])
        && !empty($ctData['contribution_id'])
        && ((int) $existingRow['contribution_id'] !== (int) $ctData['contribution_id'])
      ) {

        watchdog(
          'contribution-tracking',
          "Trying to update contribution tracking row {$ctData['id']} that " .
          "already has contribution_id {$existingRow['contribution_id']} " .
          "with new contribution id {$ctData['contribution_id']}.",
          [],
          WATCHDOG_INFO
        );

        $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
        $ContributionTrackingStatsCollector->recordChangeOfContributionIdError();
        return;
      }

      db_update('contribution_tracking')
        ->fields($ctData)
        ->condition('id', $ctData['id'])
        ->execute();
    }
    else {
      db_insert('contribution_tracking')
        ->fields($ctData)
        ->execute();
    }
  }

  protected function truncateFields($msg) {
    include_once(__DIR__ . '/../../contribution_tracking/contribution_tracking.install');
    $schema = contribution_tracking_schema();
    $truncated = $msg;
    foreach ($schema['contribution_tracking']['fields'] as $name => $field) {
      if (isset($field['length']) && isset($msg[$name])) {
        $truncated[$name] = substr($msg[$name], 0, $field['length']);
      }
    }
    return $truncated;
  }
}

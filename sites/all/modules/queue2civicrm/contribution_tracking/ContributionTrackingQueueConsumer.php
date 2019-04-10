<?php

namespace queue2civicrm\contribution_tracking;

use ContributionTrackingDataValidationException;
use wmf_common\WmfQueueConsumer;

class ContributionTrackingQueueConsumer extends WmfQueueConsumer {

  /**
   * Normalise the queue message and insert into the contribution tracking tbl
   *
   * @param array $message contribution-tracking queue message
   *
   * @return \DatabaseStatementInterface|int
   * @throws \ContributionTrackingDataValidationException
   */
  function processMessage($message) {
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

    return $this->persistContributionTrackingData($ctData);
  }

  /**
   * Insert or update a contribution tracking entry.
   *
   * @param $ctData data to be written to contribution tracking tbl
   *
   * @return \DatabaseStatementInterface|int
   * @throws \Exception
   */
  protected function persistContributionTrackingData($ctData) {
    $checkSql = "
SELECT id FROM contribution_tracking
WHERE id = :ct_id
LIMIT 1";

    $checkResult = db_query($checkSql, [
      ':ct_id' => $ctData['id'],
    ]);

    if ($checkResult->rowCount() === 1) {
      return db_update('contribution_tracking')
        ->fields($ctData)
        ->condition('id', $ctData['id'])
        ->execute();
    }
    else {
      return db_insert('contribution_tracking')
        ->fields($ctData)
        ->execute();
    }
  }
}

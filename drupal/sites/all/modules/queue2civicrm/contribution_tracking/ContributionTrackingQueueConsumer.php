<?php

namespace queue2civicrm\contribution_tracking;

use Civi\Api4\ContributionTracking;
use \Civi\WMFException\ContributionTrackingDataValidationException;
use Civi\WMFHelpers\ContributionTracking as WMFHelper;
use wmf_common\WmfQueueConsumer;

class ContributionTrackingQueueConsumer extends WmfQueueConsumer {

  /**
   * Normalise the queue message and insert into the contribution_tracking
   * and contribution_source tables
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

    $this->log("Beginning processing of contribution-tracking message {contribution_tracking_id}",
      ['contribution_tracking_id' => $message['id']]
    );

    $csData = $this->getContributionSourceData($message);
    $message = $this->truncateFields($message);

    // For the legacy table insert, pick out the fields we want and ignore
    // anything else (e.g. source_* fields). The data array to insert into
    // the new table is built in WMFHelper::getContributionTrackingParameters
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

    [$existingContributionID, $existingRow] = $this->getExisting($ctData['id']);
    if ($existingContributionID
      && !empty($ctData['contribution_id'])
      && ($existingContributionID !== (int) $ctData['contribution_id'])
    ) {
      $this->rejectChangingContributionID($ctData, $existingContributionID);
    }
    else {

      ContributionTracking::save(FALSE)
        ->addRecord(WMFHelper::getContributionTrackingParameters($message))
        ->execute();

      $this->persistContributionTrackingData($ctData, $csData, $existingRow);
    }
    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $ContributionTrackingStatsCollector->recordContributionTrackingRecord();
  }

  /**
   * Insert or update a contribution tracking entry.
   *
   * @param array $ctData data to be written to contribution_tracking tbl
   * @param array|null $csData data to be written to contribution_source tbl
   * @param array|null $existingRow
   *
   * @throws \Exception
   */
  protected function persistContributionTrackingData(array $ctData, ?array $csData, ?array $existingRow): void {
    // @todo - move all the below to a hook to run from the ContributionTracking save.
    if ($existingRow) {

      db_update('contribution_tracking')
        ->fields($ctData)
        ->condition('id', $ctData['id'])
        ->execute();

      // Only update contribution_source if message has a changed utm_source
      if ($csData !== NULL && $existingRow['utm_source'] !== $ctData['utm_source']) {
        db_update('contribution_source')
          ->fields($csData)
          ->condition('contribution_tracking_id', $ctData['id'])
          ->execute();
      }
    }
    else {
      db_insert('contribution_tracking')
        ->fields($ctData)
        ->execute();

      if ($csData !== NULL) {
        $csData['contribution_tracking_id'] = $ctData['id'];
        db_insert('contribution_source')
          ->fields($csData)
          ->execute();
      }
    }
  }

  /**
   * Log message, ensuring CiviCRM is initialized to do so.
   *
   * It's OK to call civicrm_initialize() more than once if it has
   * already been called.
   *
   * @param string $message
   * @param array $context
   */
  private function log(string $message, array $context): void {
    civicrm_initialize();
    \Civi::log('wmf')->info('contribution-tracking: ' . $message, $context);
  }

  /**
   * @param array $msg Original (untruncated) contribution-tracking queue message
   * @return array|null data for contribution_source table, or null if no good data found
   */
  protected function getContributionSourceData(array $msg): ?array {
    if (empty($msg['utm_source'])) {
      return null;
    }
    $source = $msg['utm_source'];
    // Sometimes it's just dots. Skip it instead of writing an empty row
    if (empty(str_replace('.', '', $source))) {
      return null;
    }
    // Usually just 3 segments, 4 when payment_submethod is specified, e.g. for iDEAL
    $exploded = explode('.', $source);
    if (count($exploded) > 4 || count($exploded) < 3) {
      return null;
    }
    return [
      'banner' => mb_substr($exploded[0], 0, 128),
      'landing_page' => mb_substr($exploded[1], 0, 128),
      'payment_method' => mb_substr($exploded[2], 0, 128)
    ];
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

  /**
   * @param $id
   *
   * @return array
   *  - contributionID (int|null)
   *  - existingRow (array)
   */
  protected function getExisting($id): array {
    $checkSql = "
SELECT id, contribution_id, utm_source FROM contribution_tracking
WHERE id = :ct_id
LIMIT 1";
    $checkResult = db_query($checkSql, [
      ':ct_id' => $id,
    ]);

    if ($checkResult->rowCount() > 0) {
      $existingRow = $checkResult->fetchAssoc();
      $existingContributionID = $existingRow['contribution_id'] ? (int) $existingRow['contribution_id'] : NULL;
    }
    return [$existingContributionID ?? NULL, $existingRow ?? NULL];
  }

  /**
   * @param array $ctData
   * @param $existingContributionID
   */
  protected function rejectChangingContributionID($ctData, $existingContributionID): void {
    $this->log(
      "Trying to update contribution tracking row {contribution_tracking_id} that " .
      "already has contribution_id {existing_contribution_id} " .
      "with new contribution id {contribution_id}.", [
        'contribution_tracking_id' => $ctData['id'],
        'existing_contribution_id' => $existingContributionID,
        'contribution_id' => $ctData['contribution_id'],
      ]
    );

    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $ContributionTrackingStatsCollector->recordChangeOfContributionIdError();
  }

}

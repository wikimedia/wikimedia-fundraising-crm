<?php

namespace Civi\WMFQueue;

use Civi\Api4\ContributionTracking;
use Civi\WMFException\ContributionTrackingDataValidationException;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use Civi\WMFStatistic\ContributionTrackingStatsCollector;

class ContributionTrackingQueueConsumer extends QueueConsumer {

  /**
   * Normalise the queue message and insert into the contribution_tracking
   * and contribution_source tables
   *
   * @param array $message contribution-tracking queue message
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\ContributionTrackingDataValidationException
   */
  public function processMessage(array $message): void {
    if (empty($message['id'])) {
      $error = "missing essential contribution tracking ID. Dropping message on floor." . json_encode($message);
      throw new ContributionTrackingDataValidationException($error);
    }

    $this->log("Beginning processing of contribution-tracking message {contribution_tracking_id}",
      ['contribution_tracking_id' => $message['id']]
    );

    $message = $this->truncateFields($message);

    // For the legacy table insert, pick out the fields we want and ignore
    // anything else (e.g. source_* fields). The data array to insert into
    // the new table is built in WMFHelper::getContributionTrackingParameters
    $ctData = array_filter($message, function($key) {
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

    if (!empty($ctData['contribution_id'])) {
      $existingContributionID = $this->getExistingContributionID($ctData['id']);
      if ($existingContributionID && $existingContributionID !== $ctData['contribution_id']) {
        $this->rejectChangingContributionID($ctData, $existingContributionID);
        $this->endStatistics();
        return;
      }
    }
    try {
      ContributionTracking::save(FALSE)
        ->addRecord(WMFHelper::getContributionTrackingParameters($message))
        ->execute();
    }
    catch (\CRM_Core_Exception $ex) {
      // When setting a contribution ID, we can expect a few constraint violations
      // from messages sent by the donations queue consumer after a contribution
      // insert has been rolled back. Ignore those exceptions, rethrow the rest.
      $isConstraintViolation = (
        $ex->getErrorCode() === 'constraint violation' ||
        $ex->getErrorCode() === DB_ERROR_CONSTRAINT
      );
      if (!empty($ctData['contribution_id']) || !$isConstraintViolation) {
        throw $ex;
      }
    }
    $this->endStatistics();
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

  protected function truncateFields(array $msg) {
    $fields = ContributionTracking::getFields(FALSE)->execute()->indexBy('name');
    $truncated = $msg;
    foreach ($fields as $name => $field) {
      if (isset($field['input_attrs']['maxlength']) && isset($msg[$name])) {
        $truncated[$name] = substr($msg[$name], 0, $field['input_attrs']['maxlength']);
      }
    }
    return $truncated;
  }

  /**
   * @param int $id Contribution Tracking ID.
   *
   * @return int|null
   *   - contributionID
   * @throws \CRM_Core_Exception
   */
  protected function getExistingContributionID(int $id): ?int {
    $result = ContributionTracking::get(FALSE)->addWhere('id', '=', $id)->execute()->first();
    return !empty($result['contribution_id']) ? (int) $result['contribution_id'] : NULL;
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

  /**
   * @return void
   */
  public function endStatistics(): void {
    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $ContributionTrackingStatsCollector->recordContributionTrackingRecord();
  }

}

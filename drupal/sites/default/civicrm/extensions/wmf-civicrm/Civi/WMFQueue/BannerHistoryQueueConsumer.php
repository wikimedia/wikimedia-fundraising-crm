<?php

namespace Civi\WMFQueue;

use Civi\Api4\ContributionTracking;
use Civi\WMFException\WMFException;

class BannerHistoryQueueConsumer extends QueueConsumer {

  /**
   * Validate and store messages from the banner history queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage($message) {
    if (empty($message)) {
      throw new WMFException(
        WMFException::BANNER_HISTORY,
        'Empty banner history message.'
      );
    }

    if (
      empty($message['banner_history_id']) ||
      empty($message['contribution_tracking_id'])
    ) {
      throw new WMFException(
        WMFException::BANNER_HISTORY,
        'Missing banner history or contribution tracking ID.'
      );
    }

    $bannerHistoryId = $message['banner_history_id'];
    $contributionTrackingId = $message['contribution_tracking_id'];

    if (
      !is_numeric($contributionTrackingId) ||
      !preg_match('/^[0-9a-f]{10,20}$/', $bannerHistoryId)
    ) {
      throw new WMFException(
        WMFException::BANNER_HISTORY,
        'Invalid data in banner history message.'
      );
    }

    \Civi::log('wmf')->info(
      'banner_history: About to add row for {banner_history_id}',
      ['banner_history_id' => $bannerHistoryId]
    );
    ContributionTracking::update(FALSE)
      ->addWhere('id', '=', $contributionTrackingId)
      ->addValue('banner_history_log_id', $bannerHistoryId)
      ->execute();

    \Civi::log('wmf')->info('banner_history: Processed {banner_history_id}', ['banner_history_id' => $bannerHistoryId]);
  }

}

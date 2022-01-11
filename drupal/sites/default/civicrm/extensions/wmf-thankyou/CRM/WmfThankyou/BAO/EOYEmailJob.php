<?php
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * BAO class for end of year email job tracking.
 *
 * @noinspection PhpUnused
 * @noinspection UnknownInspectionInspection
 */
class CRM_WmfThankyou_BAO_EOYEmailJob extends CRM_WmfThankyou_DAO_EOYEmailJob {

  /**
   * Get EOY email job statuses.
   *
   * @return array
   */
  public static function getStatuses(): array {
    return [
      'queued' => E::ts('Queued'),
      'sent' => E::ts('Sent'),
      'failed' => E::ts('Failed'),
    ];
  }
}

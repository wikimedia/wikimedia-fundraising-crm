<?php

namespace Civi\Api4\Action\MatchingGift;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * @method int getLimit()
 * @method $this setLimit(int $limit)
 */
class VerifyEmployerFile extends AbstractAction {

  protected $newFile;

  /**
   * Number of employers to process.
   *
   * Defaults to `0` - unlimited.
   *
   * Note: the Api Explorer sets this to `25` by default to avoid timeouts.
   * Change or remove this default for your application code.
   *
   * @var int
   */
  protected int $limit = 0;

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // download the latest updates via the matching gifts civicrm ext sync
    $syncResult = $this->syncMatchingGiftsEmployerData();

    if ($syncResult['count'] > 0) {
      // the sync pulled down new data so let's export the new employer data
      $this->generateNewExport();

      $currentEmployerFilePath = \Civi::settings()->get('matching_gifts_employer_data_file_path');
      // now lets compare the new employer data against our current version
      // and overwrite the current version if we find employer updates in the new export
      if ($this->newExportContainsUpdates(
        $currentEmployerFilePath
      )) {
        update_matching_gifts_employer_data(
          $this->getExportFilePath(),
          $currentEmployerFilePath
        );
        send_matching_gifts_update_email($currentEmployerFilePath);
      }
      else {
        // clean up our new data file if there are no updates to employer data
        unlink($this->getExportFilePath());
        \Civi::log('wmf')->info(
          'civicrm_matching_gifts_employers_check: Removing new employers data file. No employer updates found'
        );
      }
    }
  }

  /**
   * Call matching gifts sync api action.
   *
   * $syncBatchSize defaults to 0 which means unlimited. This is so we sync all
   * available updates.
   *
   * @return array $syncResult
   * @throws \CRM_Core_Exception
   * @see sites/default/civicrm/extensions/matching-gifts/api/v3/MatchingGiftPolicies/Sync.php
   */
  private function syncMatchingGiftsEmployerData(): array {
    \Civi::log('matching_gifts')->info(
      'civicrm_matching_gifts_employers_check: Initiating matching gift employers sync'
    );

    $syncParams = [
      'batch' => $this->getLimit(),
    ];
    $syncResult = civicrm_api3('MatchingGiftPolicies', 'Sync', $syncParams);

    if ($syncResult['count'] == 0) {
      \Civi::log('matching_gifts')->info(
        'civicrm_matching_gifts_employers_check: No new employers available to sync since the last update'
      );
    }
    else {
      \Civi::log('matching_gifts')->info(
        'civicrm_matching_gifts_employers_check: {count} updated employer records found',
        ['count' => $syncResult['count']]
      );
    }

    return $syncResult;
  }

  /**
   * Call matching gifts export api action and write file to a tmp location
   *
   * @throws \CRM_Core_Exception
   */
  private function generateNewExport(): void {
    $exportParams = [
      'path' => $this->getExportFilePath(),
    ];

    civicrm_api3('MatchingGiftPolicies', 'Export', $exportParams);

    \Civi::log('matching_gifts')->info(
      'civicrm_matching_gifts_employers_check: New employers data file created at {path}',
      ['path' => $this->getExportFilePath()]
    );
  }

  /**
   * @return string
   */
  public function getExportFilePath(): string {
    if (!isset($this->newFile)) {
      $this->newFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'employers-' . date('YmdHis') . '.csv';
    }
    return $this->newFile;
  }

  /**
   * Check for changes between the new and current employer data files.
   *
   * @param string $currentEmployerFilePath
   *
   * @return bool
   * @throws \CRM_Core_Exception(
   */
  private function newExportContainsUpdates(
    string $currentEmployerFilePath
  ): bool {
    $newEmployerFilePath = $this->getExportFilePath();
    $currentFileExists = file_exists($currentEmployerFilePath);
    $newFileExists = file_exists($newEmployerFilePath);

    // check if a current version of the data exists
    if ($currentFileExists) {
      // check if the new data file also exists
      if ($newFileExists) {
        // compare file data
        if (sha1_file($newEmployerFilePath) !== sha1_file(
            $currentEmployerFilePath
          )) {
          //updates detected
          \Civi::log('matching_gifts')->info(
            'civicrm_matching_gifts_employers_check: Changes found in new employer data file'
          );
          return TRUE;
        }
        else {
          // no updates found between new and current
          \Civi::log('matching_gifts')->info(
            'civicrm_matching_gifts_employers_check: No updates present in new employer file'
          );
          return FALSE;
        }
      }
      else {
        // the new employer data file doesn't exist
        throw new \CRM_Core_Exception(
          'New employer data file not found ' . $newEmployerFilePath
        );
      }
    }
    else {
      // a current file doesn't exist so the new export takes its rightful
      // place as the current export. It feels proud but is modest with the press.
      if ($newFileExists) {
        return TRUE;
      }
      else {
        // we can't find the new or current employer data file!
        throw new \CRM_Core_Exception(
          'No employer data files found! ' . $newEmployerFilePath . " - " . $currentEmployerFilePath
        );
      }
    }
  }

}

<?php

namespace Civi\Api4\Action\MatchingGift;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * @method int getLimit()
 * @method $this setLimit(int $limit)
 */
class VerifyEmployerFile extends AbstractAction {

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
  protected $limit = 0;

  public function _run(Result $result) {
    // download the latest updates via the matching gifts civicrm ext sync
    $syncResult = sync_matching_gifts_employer_data($this->getLimit());

    if ($syncResult['count'] > 0) {
      // the sync pulled down new data so let's export the new employer data
      $newExportFilePath = TEMP_EXPORT_PATH . DIRECTORY_SEPARATOR . 'employers-' . date('YmdHis') . '.csv';
      generate_new_export($newExportFilePath);

      $currentEmployerFilePath = \Civi::settings()->get('matching_gifts_employer_data_file_path');
      // now lets compare the new employer data against our current version
      // and overwrite the current verson if we find employer updates in the new export
      if (new_export_contains_updates(
        $newExportFilePath,
        $currentEmployerFilePath
      )) {
        update_matching_gifts_employer_data(
          $newExportFilePath,
          $currentEmployerFilePath
        );
        send_matching_gifts_update_email($currentEmployerFilePath);
      }
      else {
        // clean up our new data file if there are no updates to employer data
        unlink($newExportFilePath);
        \Civi::log('wmf')->info(
          'civicrm_matching_gifts_employers_check: Removing new employers data file. No employer updates found'
        );
      }
    }
  }

}

<?php

namespace Civi\Api4\Action\MatchingGift;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Omnimail\MailFactory;

/**
 * @method int getLimit()
 * @method $this setLimit(int $limit)
 */
class VerifyEmployerFile extends AbstractAction {

  private string $newFile;

  private string $currentFile;

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

      // now lets compare the new employer data against our current version
      // and overwrite the current version if we find employer updates in the new export
      if ($this->newExportContainsUpdates()) {
        $this->updateMatchingGiftsEmployerData();
        $this->sendUpdateNotification();
        $result[] = ['is_update' => TRUE];
      }
      else {
        // clean up our new data file if there are no updates to employer data
        unlink($this->getExportFilePath());
        \Civi::log('matching_gifts')->info(
          'civicrm_matching_gifts_employers_check: Removing new employers data file. No employer updates found'
        );
        $result[] = ['is_update' => FALSE];
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
   * @see ext/matching-gifts/api/v3/MatchingGiftPolicies/Sync.php
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
   * @return bool
   * @throws \CRM_Core_Exception(
   */
  private function newExportContainsUpdates(): bool {
    $newEmployerFilePath = $this->getExportFilePath();
    $currentEmployerFilePath = $this->getCurrentEmployerFilePath();
    $currentFileExists = file_exists($currentEmployerFilePath);
    $newFileExists = file_exists($newEmployerFilePath);

    // If we don't have the new file at all, we can't proceed.
    if (!$newFileExists) {
      throw new \CRM_Core_Exception(
        'New employer data file not found ' . $newEmployerFilePath
      );
    }

    // If we have a new file and don't have a current file, then the new file is definitely an update.
    if (!$currentFileExists) {
      return TRUE;
    }

    // If both files exist, compare them.
    $newFileHash = sha1_file($newEmployerFilePath);
    $currentFileHash = sha1_file($currentEmployerFilePath);

    if ($newFileHash !== $currentFileHash) {
      \Civi::log('matching_gifts')->info(
        'civicrm_matching_gifts_employers_check: Changes found in new employer data file'
      );
      return TRUE;
    }

    // If they match, no updates.
    \Civi::log('matching_gifts')->info(
      'civicrm_matching_gifts_employers_check: No updates present in new employer file'
    );
    return FALSE;
  }

  /**
   * Update the matching gifts employers data file to the newest version and
   * backup the old
   */
  private function updateMatchingGiftsEmployerData(): void {
    $newEmployerFilePath = $this->getExportFilePath();
    $currentEmployerFilePath = $this->getCurrentEmployerFilePath();
    // backup current version if it exists
    // note: this will also remove any previous backup files created.
    if (file_exists($currentEmployerFilePath)) {
      rename($currentEmployerFilePath, $currentEmployerFilePath . ".bk");
    }
    // update file to latest
    rename($newEmployerFilePath, $currentEmployerFilePath);
    \Civi::log('matching_gifts')->info(
      'civicrm_matching_gifts_employers_check: Latest employers file created at {path}',
      ['path' => $currentEmployerFilePath]
    );
  }

  /**
   * @return string
   */
  public function getCurrentEmployerFilePath(): string {
    if (!isset($this->currentFile)) {
      $this->currentFile = \Civi::settings()->get('matching_gifts_employer_data_file_path');
    }
    return $this->currentFile;
  }

  /**
   * Email fr-tech about the updated employer data file so that it can be
   * deployed.
   *
   * @throws \CRM_Core_Exception
   */
  private function sendUpdateNotification(): void {
    $toAddress = \Civi::settings()->get('wmf_matching_gifts_employer_data_update_email');
    $currentEmployerFilePath = $this->getCurrentEmployerFilePath();
    // @todo - switch to native civi function - this is not a dependency & is weird.
    $mailer = MailFactory::singleton()->getMailer();
    $email['to_address'] = $toAddress;
    $email['to_name'] = $toAddress;
    $email['from_address'] = 'fr-tech@wikimedia.org';
    $email['from_name'] = 'Employer File Updater';
    $email['subject'] = 'Matching Gifts employer file updated';

    $email['html'] = 'Matching Gifts employer file has been updated: ';
    $email['html'] .= $currentEmployerFilePath;
    $email['html'] .= '<br/>You may find it convenient to run updateemployer.sh ';
    $email['html'] .= 'checked into the /var/lib/git/tools.git repo on the puppetmaster host.<br/>';
    $email['html'] .= '<a href="https://wikitech.wikimedia.org/wiki/Fundraising/Cluster/Deployments#Matching_gifts_employers_list">';
    $email['html'] .= 'Deploy instructions</a>';

    try {
      $mailer->send($email);
      \Civi::log('matching_gifts')->info(
        'civicrm_matching_gifts_employers_check: Update notification email sent to {to_address}',
        ['to_address' => $toAddress]
      );
    }
    catch (\Exception $e) {
      // something bad happened :(
      throw new \CRM_Core_Exception(
        'Error when attempting to send matching gifts update email:  ' . $e->getMessage(
        )
      );
    }
  }

}

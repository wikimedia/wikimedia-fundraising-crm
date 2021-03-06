<?php

use Civi\WMFException\WMFException;
use SmashPig\Core\UtcDate;
use wmf_communication\Mailer;

define("TEMP_EXPORT_PATH", "/tmp");
/**
 * Implementation of hook_drush_command()
 */
function civicrm_matching_gifts_employers_check_drush_command() {
  $items = [];

  $items['civicrm-matching-gifts-employers-check'] = [
    'description' => 'Check matching gifts employers data is up to date',
    'arguments' => [
      'sync-batch-size' => 'Matching Gifts Sync API call batch parameter value (defaults to 0 which is unlimited)',
    ],
    'examples' => [
      'Standard example' => 'drush civicrm-matching-gifts-employers-check',
      'Batch example' => 'drush civicrm-matching-gifts-employers-check 20',
    ],
    'aliases' => ['cvmgc'],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_matching_gifts_employers_check_drush_help($section) {
  switch ($section) {
    case 'drush:civicrm-matching-gifts-employers-check':
      return dt('Check matching gifts employers data is up to date');
  }
}

/**
 * @param int $syncBatchSize
 *
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\WMFException\WMFException
 */
function drush_civicrm_matching_gifts_employers_check($syncBatchSize = 0) {
  civicrm_initialize();

  // download the latest updates via the matching gifts civicrm ext sync
  $syncResult = sync_matching_gifts_employer_data($syncBatchSize);

  if ($syncResult['count'] > 0) {
    // the sync pulled down new data so let's export the new employer data
    $newExportFilePath = TEMP_EXPORT_PATH . DIRECTORY_SEPARATOR . 'employers-' . UtcDate::getUtcDatabaseString() . '.csv';
    generate_new_export($newExportFilePath);

    $currentEmployerFilePath = variable_get(
      'matching_gifts_employer_data_file_path',
      '/srv/matching_gifts/employers.csv'
    );
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
      watchdog(
        'civicrm_matching_gifts_employers_check',
        'Removing new employers data file. No employer updates found'
      );
    }
  }

  drush_print('Matching Gifts employers data check complete');
}

/**
 * Call matching gifts sync api action.
 *
 * $syncBatchSize defaults to 0 which means unlimited. This is so we sync all
 * available updates.
 *
 * @param int $syncBatchSize sync api call batch size
 *
 * @return array $syncResult
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\WMFException\WMFException
 * @see sites/default/civicrm/extensions/matching-gifts/api/v3/MatchingGiftPolicies/Sync.php
 */
function sync_matching_gifts_employer_data($syncBatchSize) {
  watchdog(
    'civicrm_matching_gifts_employers_check',
    'Initiating matching gift employers sync'
  );

  $syncParams = [
    'batch' => $syncBatchSize,
  ];
  $syncResult = civicrm_api3('MatchingGiftPolicies', 'Sync', $syncParams);

  if ($syncResult['is_error']) {
    throw new WMFException(
      WMFException::MISSING_MANDATORY_DATA,
      'There was a problem syncing matching gift employers: ' . $syncResult['error_message']
    );
  }

  if ($syncResult['count'] == 0) {
    watchdog(
      'civicrm_matching_gifts_employers_check',
      'No new employers available to sync since the last update'
    );
  }
  else {
    watchdog(
      'civicrm_matching_gifts_employers_check',
      '%count updated employer records found',
      ['%count' => $syncResult['count']],
      WATCHDOG_INFO
    );
  }

  return $syncResult;
}

/**
 * Call matching gifts export api action and write file to a tmp location
 *
 * @param string $exportFilePath file path to write export to
 *
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\WMFException\WMFException
 */
function generate_new_export($exportFilePath) {
  $exportParams = [
    'path' => $exportFilePath,
  ];

  $exportResult = civicrm_api3('MatchingGiftPolicies', 'Export', $exportParams);
  if ($exportResult['is_error']) {
    throw new WMFException(
      WMFException::MISSING_MANDATORY_DATA,
      'There was a problem exporting matching gift employers data: ' . $exportResult['error_message']
    );
  }

  watchdog(
    'civicrm_matching_gifts_employers_check',
    'New employers data file created at %path',
    ['%path' => $exportFilePath],
    WATCHDOG_INFO
  );
}

/**
 * Check for changes between the new and current employer data files.
 *
 * @param string $newEmployerFilePath
 * @param string $currentEmployerFilePath
 *
 * @return bool
 * @throws \Civi\WMFException\WMFException
 */
function new_export_contains_updates(
  $newEmployerFilePath,
  $currentEmployerFilePath
) {
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
        watchdog(
          'civicrm_matching_gifts_employers_check',
          'Changes found in new employer data file'
        );
        return TRUE;
      }
      else {
        // no updates found between new and current
        watchdog(
          'civicrm_matching_gifts_employers_check',
          'No updates present in new employer file'
        );
        return FALSE;
      }
    }
    else {
      // the new employer data file doesn't exist
      throw new WMFException(
        WMFException::MISSING_MANDATORY_DATA,
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
      throw new WMFException(
        WMFException::MISSING_MANDATORY_DATA,
        'No employer data files found! ' . $newEmployerFilePath . " - " . $currentEmployerFilePath
      );
    }
  }
}

/**
 * Update the matching gifts employers data file to the newest version and
 * backup the old
 *
 * @param string $newEmployerFilePath
 * @param string $currentEmployerFilePath
 */
function update_matching_gifts_employer_data(
  $newEmployerFilePath,
  $currentEmployerFilePath
) {
  // backup current version if it exists
  // note: this will also remove any previous backup files created.
  if (file_exists($currentEmployerFilePath)) {
    rename($currentEmployerFilePath, $currentEmployerFilePath . ".bk");
  }
  // update file to latest
  rename($newEmployerFilePath, $currentEmployerFilePath);
  watchdog(
    'civicrm_matching_gifts_employers_check',
    'Latest employers file created at %path',
    ['%path' => $currentEmployerFilePath],
    WATCHDOG_INFO
  );
}

/**
 * Email fr-tech about the updated employer data file so that it can be
 * deployed.
 *
 * @param string $currentEmployerFilePath
 *
 * @throws \Civi\WMFException\WMFException
 */
function send_matching_gifts_update_email($currentEmployerFilePath) {
  $toAddress = variable_get(
    'matching_gifts_employer_data_update_email',
    'fr-tech@wikimedia.org'
  );

  $mailer = Mailer::getDefault();
  $email['to_address'] = $toAddress;
  $email['to_name'] = $toAddress;
  $email['from_address'] = 'fr-tech@wikimedia.org';
  $email['from_name'] = 'Employer File Updater';
  $email['subject'] = 'Matching Gifts employer file updated';

  $email['html'] = 'Matching Gifts employer file has been updated: ';
  $email['html'] .= $currentEmployerFilePath;

  try {
    $mailer->send($email);
    watchdog(
      'civicrm_matching_gifts_employers_check',
      'Update notification email sent to ' . $toAddress
    );
  } catch (Exception $e) {
    // something bad happened :(
    throw new WMFException(
      WMFException::BAD_EMAIL,
      'Error when attempting to send matching gifts update email:  ' . $e->getMessage(
      )
    );
  }
}

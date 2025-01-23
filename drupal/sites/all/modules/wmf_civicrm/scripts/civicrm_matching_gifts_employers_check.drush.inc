<?php

use Civi\Omnimail\MailFactory;
use Civi\WMFException\WMFException;
use SmashPig\Core\UtcDate;

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
 * @throws \CRM_Core_Exception
 * @throws \Civi\WMFException\WMFException
 */
function drush_civicrm_matching_gifts_employers_check($syncBatchSize = 0) {
  civicrm_initialize();

  \Civi\Api4\MatchingGift::verifyEmployerFile(FALSE)
    ->setLimit($syncBatchSize)
    ->execute();

  drush_print('Matching Gifts employers data check complete');
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
  $toAddress = \Civi::settings()->get('wmf_matching_gifts_employer_data_update_email');

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
    \Civi::log('wmf')->info(
      'civicrm_matching_gifts_employers_check: Update notification email sent to {to_address}',
    ['to_address' => $toAddress]
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

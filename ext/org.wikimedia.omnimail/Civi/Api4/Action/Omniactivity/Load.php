<?php
namespace Civi\Api4\Action\Omniactivity;

use Civi\Api4\Action\Omniaction;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Generic\Result;
use CRM_Omnimail_Helper;
use League\Csv\UnavailableStream;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setType(bool $array)
 * @method array getType()
 * @method $this setJobIdentifier(string $jobIdentifier)
 * @method string getJobIdentifier()
 *
 * @package Civi\Api4
 */
class Load extends Omniaction {

  /**
   * Types of activities to get - from snooze, remind_me_later, opt_out, unsubscribe.
   *
   * @var array
   */
  protected array $type = [];

  /**
   * @var string
   */
  protected string $jobIdentifier = '';

  private array $outcomes = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $omniObject = new \CRM_Omnimail_Omniactivity([
      'mail_provider' => $this->getMailProvider(),
      'job_identifier' => $this->jobIdentifier,
      'start_date' => $this->start ?: NULL,
      'end_date' => $this->end ?: NULL,
    ]);

    $offset = $omniObject->getOffset();
    try {
      $rows = $omniObject->getResult([
        'client' => $this->getClient(),
        'mail_provider' => $this->getMailProvider(),
        'database_id' => $this->getDatabaseID(),
        'check_permissions' => $this->getCheckPermissions(),
        'type' => $this->getType(),
        'limit' => $this->limit,
        'start_date' => $this->start ?: NULL,
        'end_date' => $this->end ?: NULL,
      ]);
      CRM_Omnimail_Helper::logout();
    }
    catch (\CRM_Omnimail_IncompleteDownloadException $e) {
      $omniObject->saveJobSetting([
        'retrieval_parameters' => $omniObject->getRetrievalParameters(),
        // The progress end timestamp is the end date of the current request.
        // Once all the rows from the request are retrieved it will be
        // saved as the last_timestamp.
        'progress_end_timestamp' => $omniObject->endTimeStamp,
      ], 'omnirecipient_incomplete_download');
      CRM_Omnimail_Helper::logout();
      return;
    }
    catch (UnavailableStream $e) {
      // The csv could not be loaded - forget about it and try again.
      // This might happen if our file had been removed & when we try upstream
      // it is not there to fetch.
      // Arguably we should set the last_timestamp to progress_end_timestamp
      // but starting again from known success feels safer.
      $omniObject->saveJobSetting([
        'progress_end_timestamp' => 'null',
        'offset' => 'null',
        'retrieval_parameters' => 'null',
      ], 'omniactivity_file_failed');
      throw new \CRM_Core_Exception('file error - try again');
    }

    foreach ($rows as $row) {
      if (!$row['contact_id']) {
        // This would be like an RML contact who is not already in CiviCRM.
        // For now we will skip them but we could create them.
        $this->addOutcome('contact_not_found_in_acoustic', $row);
        $result[] = $row;
        continue;
      }
      $contact = Contact::get(FALSE)
        ->addWhere('id', '=', $row['contact_id'])
        ->addSelect('email_primary.email', 'is_opt_out')
        ->execute()->first();

      if (!$contact) {
        $this->addOutcome('contact_not_found (contact lookup from ID)', $row);
        $result[] = $row;
        continue;
      }
      $activityType = $row['activity_type'];

      // Create activity.
      $existing = Activity::get(FALSE)
        ->addWhere('activity_type_id:name', '=', $activityType)
        ->addWhere('activity_date_time', 'BETWEEN', [
          // Ignore if the activity already exists give or take an hour.
          // Note that there are many examples of 2 activities within a second of each
          // other. It is unknown whether we gain anything by going a whole hour each side
          // but it felt like a reasonable time to check to avoid noise.
          date('Y-m-d H:i:s', strtotime('- 1 hour', strtotime($row['recipient_action_datetime']))),
          date('Y-m-d H:i:s', strtotime('+ 1 hour', strtotime($row['recipient_action_datetime']))),
        ])
        ->addWhere('source_contact_id', '=', $row['contact_id'])
        ->addSelect('id')
        ->execute()->first();
      if ($existing) {
        $this->addOutcome('activity_skipped_(exists)', $row);
        $row['activity_id'] = $existing['id'];
        $result[] = $row;
        continue;
      }

      $this->addOutcome('activity_created_' . $activityType, $row);

      $row['activity_id'] = Activity::create(FALSE)
        ->setValues([
          'activity_type_id:name' => $activityType,
          'source_contact_id' => $row['contact_id'],
          'subject' => $row['subject'],
          'details' => $row['referrer_url'] ?: $row['mailing_identifier'],
          'activity_date_time' => $row['recipient_action_datetime'],
        ])
        ->execute()->single()['id'];
      if ($activityType === 'EmailSnoozed') {
        $snoozedUntil = strtotime('+ 90 days', strtotime($row['recipient_action_datetime']));
        if ($snoozedUntil > time()) {
          // Update the snooze date - this could re-snooze some, not a bad thing.
          Email::update(FALSE)
            ->addWhere('email', '=', $row['email'])
            ->addValue('email_settings.snooze_date', date('Y-m-d', $snoozedUntil))
            ->execute();
        }
      }
      $result[] = $row;
    }
    if (empty($rows) || count($rows) < $this->limit) {
      $omniObject->saveJobSetting([
        'last_timestamp' => $omniObject->endTimeStamp,
        'progress_end_timestamp' => 'null',
        'offset' => 'null',
        'retrieval_parameters' => 'null',
      ], 'omniactivity_file_fully_processed');
    }
    else {
      $omniObject->saveJobSetting([
        'retrieval_parameters' => $omniObject->getRetrievalParameters(),
        // The progress end timestamp is the end date of the current request.
        // Once all the rows from the request are retrieved it will be
        // saved as the last_timestamp.
        'progress_end_timestamp' => $omniObject->endTimeStamp,
        'offset' => $offset + count($rows) + $omniObject->getSkippedRows(),
      ], 'omniactivity_file_partially_processed');
    }

    \Civi::log('omnimail')->info('activity load completed with {outcomes}', ['outcomes' => $this->outcomes]);
  }

  /**
   * @param string $type
   * @param array $row
   */
  protected function addOutcome(string $type, array &$row): void {
    // Outcomes found so far
    // contact_not_found_form_Remind Me Later
    // contact_not_found_form_rml_phone
    // activity_exists_form_Remind Me Later
    $key = $type . '_' . $row['event_type'] . '_' . ($row['event_name'] === 'RML - Phone' ? 'rml_phone' : $row['action_url_name']);
    if (!isset($this->outcomes[$key])) {
      $this->outcomes[$key] = 0;
      asort($this->outcomes);
    }
    $row['outcome'] = $key;
    $this->outcomes[$key]++;
  }

}

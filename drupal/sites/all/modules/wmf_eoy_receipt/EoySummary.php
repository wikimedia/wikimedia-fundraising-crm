<?php

namespace wmf_eoy_receipt;

use Civi\Api4\Action\EOYEmail\Render;
use Civi\Api4\EOYEmail;
use wmf_communication\Templating;
use wmf_communication\Translation;
use Civi\Omnimail\MailFactory;

class EoySummary {

  static protected $templates_dir;

  static protected $template_name;

  protected $batch = 100;

  /**
   * Year to send receipts for, defaults to last year.
   *
   * @var int
   */
  protected $year;

  /**
   * Optional contact id - used for limited test sends.
   *
   * @var int|null
   */
  protected $contact_id;

  protected $from_address;

  protected $from_name;

  /**
   * Temporary tables.
   *
   * These are instances of CRM_Utils_SQL_TempTable and are dropped when the object deconstructs.
   *
   * @var \CRM_Utils_SQL_TempTable[]
   */
  protected $temporaryTables = [];

  protected $job_id;

  /**
   * EoySummary constructor.
   *
   * @param array $options
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($options = []) {
    $this->year = $options['year'] ?? (date('Y') - 1);
    $this->batch = $options['batch'] ?? 100;
    $this->contact_id = $options['contact_id'] ?? NULL;
    $this->job_id = $options['job_id'] ?? NULL;

    $this->from_address = variable_get('thank_you_from_address', NULL);
    $this->from_name = variable_get('thank_you_from_name', NULL);

    self::$templates_dir = __DIR__ . '/templates';
    self::$template_name = 'eoy_thank_you';
  }

  /**
   * FIXME remove in favour of calling makeJob directly.
   *
   * @return int|false the job ID for use in scheduling email sends
   * @throws \Exception
   */
  public function calculate_year_totals() {
    $job = EOYEmail::makeJob(FALSE)
      ->setYear($this->year);
    if ($this->contact_id) {
      $job->setContactID($this->contact_id);
    }
    $this->job_id = $job->execute()->first()['job_id'];
    return $this->job_id;
  }

  public function send_letters() {
    if (!$this->from_address || !$this->from_name) {
      throw new \Exception('Must configure a valid return address in the Thank-you module');
    }
    $mailer = MailFactory::singleton();
    $succeeded = 0;
    $failed = 0;

    $emails = EOYEmail::render(FALSE)
      ->setLimit($this->batch)
      ->setJobID($this->job_id)
      ->setYear($this->year)
      ->execute();

    foreach ($emails as $email) {
      try {
        $email['from_name'] = $this->from_name;
        $email['from_address'] = $this->from_address;
        $success = $mailer->send($email, []);
      }
      // Should be just phpMailer exception but weird normalizeConten throws WMFException
      catch (\Exception $e) {
        // Invalid email address or something
        \Civi::log('wmf')->info('wmf_eoy_receipt send error ' . $e->getMessage());
        $success = FALSE;
      }

      if ($success) {
        // This second call to getContactIds is a little repetitive - but
        // makes sense for now as we separate the parts out.
        $this->record_activities($email);
        $status = 'sent';
        $succeeded += 1;
      }
      else {
        $status = 'failed';
        $failed += 1;
      }

      \CRM_Core_DAO::executeQuery('UPDATE wmf_eoy_receipt_donor SET status = %1 WHERE email = %2', [
        1 => [$status, 'String'],
        2 => [$email['to_address'], 'String'],
      ]);
    }

    \Civi::log('wmf')->info('wmf_eoy_receipt Successfully sent {succeeded} messages, failed to send {failed} messages.', [
      'succeeded' => $succeeded,
      'failed' => $failed,
    ]);
  }

  protected function record_activities(array $email) {
    $emailRecords = civicrm_api3('Email', 'get', [
      'email' => $email['to_address'],
      'is_primary' => TRUE,
      'contact_id.is_deleted' => FALSE,
      'return' => 'contact_id',
    ])['values'];
    foreach ($emailRecords as $emailRecord) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id' => 'wmf_eoy_receipt_sent',
        'source_contact_id' => $emailRecord['contact_id'],
        'target_contact_id' => $emailRecord['contact_id'],
        'assignee_contact_id' => $emailRecord['contact_id'],
        'subject' => "Sent contribution summary receipt for year $this->year to {$email['to_address']}",
        'details' => $email['html'],
      ]);
    }
  }

}

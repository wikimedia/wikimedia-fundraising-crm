<?php


namespace Civi\Api4\Action\EOYEmail;

use API_Exception;
use Civi;
use Civi\Api4\EOYEmail;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Omnimail\MailFactory;
use CRM_Core_DAO;
use Exception;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method int getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setYear(int $year) Set the year
 * @method int getLimit() Get the limit
 * @method $this setLimit(int $limit) Set the limit
 */
class Send extends AbstractAction {

  /**
   * Year.
   *
   * Required.
   *
   * @var int
   */
  protected $year;

  /**
   * @var array
   */
  protected $failed = [];

  /**
   * Limit.
   *
   * Currently 1 is the only possible number as contact
   * id is required.
   *
   * @var int
   */
  protected $limit = 100;

  /**
   * If provided then only this contact ID will be emailed.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Get the year, defaulting to last year.
   *
   * @return int
   */
  protected function getYear(): int {
    return $this->year ?? (date('Y') - 1);
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function _run(Result $result): void {
    if (!$this->getContactID() && $this->isJobEmpty()) {
      throw new API_Exception('All emails for year ' . $this->getYear() . ' have been sent');
    }
    $result[] = $this->sendLetters();
  }


  /**
   * Send em out!
   *
   * @return array
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function sendLetters(): array {
    $fromAddress = variable_get('thank_you_from_address');
    $fromName = variable_get('thank_you_from_name');
    if (!$fromAddress || !$fromName) {
      throw new API_Exception('Must configure a valid return address in the Thank-you module');
    }
    $mailer = MailFactory::singleton();
    $succeeded = 0;
    $initialTime = time();

    $emails = (array) EOYEmail::render(FALSE)
      ->setLimit($this->getLimit())
      ->setYear($this->getYear())
      ->setContactID($this->getContactID())
      ->execute();

    if (isset($emails['parse_failures'])) {
      $this->failed = $emails['parse_failures'];
      unset($emails['parse_failures']);
      CRM_Core_DAO::executeQuery(
        "UPDATE wmf_eoy_receipt_donor SET status = 'failed'
        WHERE email IN (%1)",
        [1 => [implode("', '", $this->failed), 'String']]
      );
    }

    $failed = count($this->failed);
    foreach ($emails as $email) {
      try {
        $email['from_name'] = $fromName;
        $email['from_address'] = $fromAddress;
        $success = $mailer->send($email, []);
      }
        // Should be just phpMailer exception but weird normalizeContent throws WMFException
      catch (Exception $e) {
        // Invalid email address or something
        Civi::log('wmf')->info('wmf_eoy_receipt send error ' . $e->getMessage());
        $success = FALSE;
      }

      if ($success) {
        // This second call to getContactIds is a little repetitive - but
        // makes sense for now as we separate the parts out.
        $this->recordActivities($email);
        $status = 'sent';
        ++$succeeded;
      }
      else {
        $status = 'failed';
        ++$failed;
      }

      CRM_Core_DAO::executeQuery('UPDATE wmf_eoy_receipt_donor SET status = %1 WHERE email = %2', [
        1 => [$status, 'String'],
        2 => [$email['to_address'], 'String'],
      ]);
    }

    Civi::log('wmf')->info('wmf_eoy_receipt Successfully sent {succeeded} messages, failed to send {failed} messages.', [
      'succeeded' => $succeeded,
      'failed' => $failed,
    ]);
    return [
      'sent' => $succeeded,
      'failed' => $failed,
      'total_attempted' => $succeeded + $failed,
      'remaining' => CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM wmf_eoy_receipt_donor WHERE status = 'queued' AND year = " . $this->getYear()),
      'year' => $this->getYear(),
      'time_taken' => time() - $initialTime,
    ];
  }

  /**
   * @param array $email
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordActivities(array $email): void {
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
        'subject' => "Sent contribution summary receipt for year " . $this->getYear() . " to {$email['to_address']}",
        'details' => $email['html'],
      ]);
    }
  }

  /**
   * Is the planned job empty of emails to send to.
   *
   * @return bool
   */
  protected function isJobEmpty(): bool {
    return !CRM_Core_DAO::singleValueQuery(
      "SELECT count(*) FROM wmf_eoy_receipt_donor WHERE status = 'queued' AND year = " . $this->getYear()
    );
  }

}

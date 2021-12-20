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
   */
  public function sendLetters(): array {
    $fromAddress = variable_get('thank_you_from_address');
    $fromName = variable_get('thank_you_from_name');
    if (!$fromAddress || !$fromName) {
      throw new API_Exception('Must configure a valid return address in the Thank-you module');
    }
    $mailer = MailFactory::singleton();
    $succeeded = $failed = $attempted = 0;
    $initialTime = time();
    if ($this->getContactID()) {
      $this->setLimit(1);
    }
    while ($attempted < $this->getLimit()) {
      $emails = (array) EOYEmail::render(FALSE)
        ->setLimit(1)
        ->setYear($this->getYear())
        ->setContactID($this->getContactID())
        ->execute();

      if (empty($emails)) {
        // We have probably reached the end....
        $attempted = $this->getLimit();
      }

      if (isset($emails['parse_failures'])) {
        ++$failed;
        $this->markFailed(reset($emails['parse_failures']), 'failed to parse');
        unset($emails['parse_failures']);
      }

      foreach ($emails as $email) {
        try {
          $email['from_name'] = $fromName;
          $email['from_address'] = $fromAddress;
          if (!$mailer->send($email, [])) {
            throw new API_Exception('Unknown send error');
          }
          $this->recordActivities($email);
          ++$succeeded;
          CRM_Core_DAO::executeQuery('UPDATE wmf_eoy_receipt_donor SET status = "sent" WHERE email = %1', [
            1 => [$email['to_address'], 'String'],
          ]);
        }
          // Should be just phpMailer exception but weird normalizeContent throws WMFException
        catch (Exception $e) {
          // Invalid email address or something
          $this->markFailed($email['to_address'], 'wmf_eoy_receipt send error', $e->getMessage());
          ++$failed;
        }
      }
      $attempted ++;
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
      // May as well return this if set in case they think they passed it & didn't.
      'contact_id' => $this->getContactID(),
      'limit' => $this->getLimit(),
    ];
  }

  /**
   * @param array $email
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordActivities(array $email): void {
    foreach ($email['contactIDs'] as $contactID) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id' => 'wmf_eoy_receipt_sent',
        'source_contact_id' => $contactID,
        'target_contact_id' => $contactID,
        'assignee_contact_id' => $contactID,
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

  /**
   * Mark an email send as having failed.
   *
   * @param string $email
   * @param string $errorType
   * @param string $detail
   */
  protected function markFailed(string $email, string $errorType, string $detail = ''): void {
    CRM_Core_DAO::executeQuery(
      "UPDATE wmf_eoy_receipt_donor SET status = 'failed'
   WHERE email = %1",
      [1 => [$email, 'String']]
    );
    Civi::log('wmf')
      ->info($errorType . ($detail ? ' ' . $detail : ''));
  }

}

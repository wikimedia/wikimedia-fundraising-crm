<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\EOYEmail;
use Civi\Api4\Exception\EOYEmail\NoContributionException;
use Civi\Api4\Exception\EOYEmail\NoEmailException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Omnimail\MailFactory;
use Civi\WMFThankYou\From;
use CRM_Core_DAO;
use Exception;

/**
 * Class Send.
 *
 * Send EOY receipt emails, either as a batch or for one contact ID
 *
 * @method int getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setYear(int $year) Set the year
 * @method int getTimeLimit() Get the time limit in seconds
 * @method $this setTimeLimit(int $timeLimit) Set the time limit in seconds
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
   * Email Limit.
   *
   * @var int
   */
  protected $limit = 100;

  /**
   * Time limit in seconds.
   *
   * @var int
   */
  protected $timeLimit = 0;

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
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if (!$this->getContactID() && $this->isJobEmpty()) {
      throw new \CRM_Core_Exception('All emails for year ' . $this->getYear() . ' have been sent');
    }
    $result[] = $this->sendLetters();
  }

  /**
   * Send em out!
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function sendLetters(): array {
    $fromAddress = From::getFromAddress('eoy');
    $fromName = From::getFromName('eoy');
    if (!$fromAddress || !$fromName) {
      throw new \CRM_Core_Exception('Must configure a valid return address in the Thank-you module');
    }
    $mailer = MailFactory::singleton();
    $succeeded = $failed = $attempted = 0;
    $initialTime = time();
    if ($this->getContactID()) {
      $this->setLimit(1);
    }
    while (
      $attempted < $this->getLimit() &&
      ($this->getTimeLimit() === 0 || time() < $initialTime + $this->getTimeLimit())
    ) {
      try {
        $emails = (array) EOYEmail::render(FALSE)
          ->setLimit(1)
          ->setYear($this->getYear())
          ->setContactID($this->getContactID())
          ->execute();
      }
      catch (NoEmailException| NoContributionException $e) {
        // Invalid email address or something
        $this->markFailed($e->getExtraParams()['email'], 'wmf_eoy_receipt send error', $e->getMessage());
        $failed++;
        $attempted++;
        continue;
      }

      if (empty($emails)) {
        // We have probably reached the end....
        $attempted = $this->getLimit();
      }

      if (isset($emails['parse_failures'])) {
        ++$failed;
        $this->markFailed(reset($emails['parse_failures']), 'failed to parse');
        unset($emails['parse_failures']);
      }
      // @todo - use reset($emails) as only 1
      foreach ($emails as $email) {
        try {
          $email['from_name'] = $fromName;
          $email['from_address'] = $fromAddress;
          if (!$mailer->send($email, [])) {
            throw new \CRM_Core_Exception('Unknown send error');
          }
          $this->recordActivities($email);
          ++$succeeded;
          CRM_Core_DAO::executeQuery('UPDATE wmf_eoy_receipt_donor SET status = "sent" WHERE email = %1 AND year = %2', [
            1 => [$email['to_address'], 'String'],
            2 => [$this->getYear(), 'Integer'],
          ]);
        }
          // Should be just phpMailer exception but need to test post changes in phpmailer to remove wmf exception.
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
   * Record activities.
   *
   * We record a single activity linked to all the contacts that were covered
   * in the email.
   *
   * If merged these activity contact records will be merged (rather than the
   * contact misleadingly winding up with more than one activity).
   *
   * Note the source contact & assigned contact are 'pick any one' - arguably we
   * should leave assigned empty. The target really is the one that matters.
   *
   * @param array $email
   *
   * @throws \CRM_Core_Exception
   */
  protected function recordActivities(array $email): void {
    $firstContactID = reset($email['contactIDs']);
    civicrm_api3('Activity', 'create', [
      'activity_type_id' => 'wmf_eoy_receipt_sent',
      'source_contact_id' => $firstContactID,
      'target_contact_id' => $email['contactIDs'],
      'assignee_contact_id' => $firstContactID,
      'subject' => "Sent contribution summary receipt for year " . $this->getYear() . " to {$email['to_address']}",
      'details' => $email['html'],
    ]);
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

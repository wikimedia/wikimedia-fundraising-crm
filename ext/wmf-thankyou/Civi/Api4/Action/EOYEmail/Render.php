<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\Exception\EOYEmail\NoContributionException;
use Civi\Api4\Exception\EOYEmail\NoEmailException;
use Civi\Api4\Exception\EOYEmail\ParseException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WorkflowMessage;

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
 * @method $this setStartDateTime(string $dateTime)
 * @method $this setEndDateTime(string $dateTime)
 * @method $this setDateRelative(string $dateString)
 */
class Render extends AbstractAction {

  /**
   * Contact ID.
   *
   * Optional, if provided not only recurring emails will be included.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Year.
   *
   * @var int
   */
  protected $year;

  /**
   * @var string
   */
  protected $startDateTime;

  /**
   * @var string
   */
  protected $dateRelative;

  /**
   * @var string
   */
  protected $endDateTime;

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
   * Get the year, defaulting to last year.
   *
   * @return string
   */
  protected function getStartDate(): string {
    if ($this->dateRelative) {
      [$relativeTerm, $unit] = explode('.', $this->dateRelative);
      return \CRM_Utils_Date::relativeToAbsolute($relativeTerm, $unit)['from'];
    }
    return (string) $this->startDateTime;
  }

  /**
   * Get the year, defaulting to last year.
   *
   * @return string
   */
  protected function getEndDate(): string {
    if ($this->dateRelative) {
      [$relativeTerm, $unit] = explode('.', $this->dateRelative);
      $date = \CRM_Utils_Date::relativeToAbsolute($relativeTerm, $unit)['to'];
      if (strlen($date) === 8) {
        // Probably never true after https://github.com/civicrm/civicrm-core/commit/2dd704eb980eda359d5dd282b7cf63ff825a3d2b
        $date .= '235959';
      }
      return $date;
    }
    return (string) $this->endDateTime;
  }

  /**
   * Get the year, defaulting to last year.
   *
   * @return null|int
   */
  protected function getYear(): ?int {
    return $this->year ?? NULL;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if ($this->getContactID()) {
      $email = Civi\Api4\Email::get(FALSE)
        ->addWhere('contact_id', '=', $this->getContactID())
        ->addWhere('is_primary', '=', TRUE)
        ->addWhere('on_hold', '=', 0)
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->execute()->first()['email'];
      if (!$email) {
        throw new NoEmailException('no valid email for contact_id ' . $this->getContactID());
      }
      $result[$email] = $this->renderLetter($email);
      return;
    }

    $row = \CRM_Core_DAO::executeQuery("
      SELECT *
      FROM wmf_eoy_receipt_donor
      WHERE
      status = 'queued'
      LIMIT %1", [1 => [$this->getLimit(), 'Integer']]);
    while ($row->fetch()) {
      try {
        $result[$row->email] = $this->renderLetter($row->email);
      }
      catch (ParseException $e) {
        $result['parse_failures'][] = $row->email;
      }
    }
  }

  /**
   * Render the letter to be sent.
   *
   * @param string $email
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function renderLetter(string $email): array {
    $contactDetails = $this->getContactDetailsForEmail($email);

    $template_params = [
      'year' => $this->getYear(),
      'contactIDs' => $contactDetails['ids'],
      'contactID' => end($contactDetails['ids']),
      'locale' => $contactDetails['language'],
      'startDateTime' => $this->getStartDate(),
      'endDateTime' => $this->getEndDate(),
    ];

    try {
      $rendered = WorkflowMessage::render(FALSE)
        ->setValues($template_params)
        ->setLanguage($contactDetails['language'])
        ->setWorkflow('eoy_thank_you')
        ->execute()->first();
    }
    catch (\ParseError $e) {
      throw new ParseException('Failed to parse template', 'parse_error');
    }
    catch (NoContributionException $e) {
      // Catch the error to add the email - which the original class didn't know.
      throw new NoContributionException($e->getMessage(), 'no_contribution', ['email' => $email]);
    }

    return [
      'to_name' => $contactDetails['display_name'],
      'to_address' => $email,
      'subject' => trim($rendered['subject']),
      'html' => str_replace('<p></p>', '', $rendered['html']),
      'contactIDs' => $contactDetails['ids'],
    ];
  }

  /**
   * Get contact IDs associated with an email address
   *
   * @param string $email
   *
   * @return array IDs and language of non-deleted contacts with that email
   * @throws \CRM_Core_Exception
   */
  protected function getContactDetailsForEmail(string $email): array {
    $emailRecords = Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addSelect('contact_id', 'contact_id.preferred_language', 'contact_id.display_name')
      ->addJoin('Contact AS contact', 'LEFT')
      ->addOrderBy('contact.wmf_donor.all_funds_last_donation_date')
      ->execute();
    $contactDetails = [];
    foreach ($emailRecords as $emailRecord) {
      $contactDetails['ids'][] = $emailRecord['contact_id'];
      $contactDetails['language'] = $emailRecord['contact_id.preferred_language'];
      $contactDetails['display_name'] = $emailRecord['contact_id.display_name'];
    }
    if (empty($contactDetails['ids'])) {
      throw new NoEmailException('email is not attached (anymore?) to a valid contact: ' . $email, 'eoy_fail', ['email' => $email]);
    }
    return $contactDetails;
  }

}

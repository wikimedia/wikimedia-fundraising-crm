<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use wmf_communication\Translation;
use Civi\EoySummary;

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
   * Required.
   *
   * @var int
   */
  protected $year;

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
   */
  public function _run(Result $result): void {
    if ($this->getContactID()) {
      $email = Civi\Api4\Email::get(FALSE)
        ->addWhere('contact_id', '=', $this->getContactID())
        ->addWhere('is_primary', '=', TRUE)
        ->addWhere('on_hold', '=', 0)
        ->execute()->first()['email'];
      if (!$email) {
        throw new \API_Exception('no valid email for contact_id ' . $this->getContactID());
      }
      $result[$this->getContactID()] = $this->renderLetter($email);
      return;
    }

    $row = \CRM_Core_DAO::executeQuery("
      SELECT *
      FROM wmf_eoy_receipt_donor
      WHERE
      status = 'queued'
      AND year = %1
      LIMIT %2", [1 => [$this->getYear(), 'Integer'], 2 => [$this->getLimit(), 'Integer']]);
    while ($row->fetch()) {
      $result[$row->email] = $this->renderLetter($row->email);
    }
  }

  /**
   * Render the letter to be sent.
   *
   * @param string $email
   *
   * @return array
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function renderLetter(string $email): array {
    $contactDetails = $this->getContactDetailsForEmail($email);
    $activeRecurring = $this->doContactsHaveActiveRecurring($contactDetails['ids']);

    $template_params = [
      'year' => $this->getYear(),
      'active_recurring' => $activeRecurring,
      'contactIDs' => $contactDetails['ids'],
      'contactId' => end($contactDetails['ids']),
      'locale' => $contactDetails['language'],
    ];
    $templateStrings = Civi\Api4\Message::load(FALSE)
      ->setLanguage($contactDetails['language'])
      ->setFallbackLanguage('en_US')
      ->setWorkflow('eoy_thank_you')->execute()->first();
    $template = ['workflow' => 'eoy_thank_you'];
    foreach ($templateStrings as $key => $string) {
      $template[$key] = $string['string'];
    }
    $swapLocale = \CRM_Utils_AutoClean::swapLocale($contactDetails['language']);
    $rendered = Civi\Api4\WorkflowMessage::render(FALSE)
      ->setMessageTemplate($template)
      ->setValues($template_params)
      ->setWorkflow('eoy_thank_you')
      ->execute()->first();

    return [
      'to_name' => $contactDetails['display_name'],
      'to_address' => $email,
      'subject' => trim($rendered['subject']),
      'html' => str_replace('<p></p>', '', $rendered['html']),
    ];
  }

  /**
   * Get contact IDs associated with an email address
   *
   * @param string $email
   *
   * @return array IDs and language of non-deleted contacts with that email
   * @throws \API_Exception
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
    return $contactDetails;
  }

  /**
   * Determine whether any of an array of contact IDs have an active recurring
   * donation associated.
   *
   * @param array $contactIds
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function doContactsHaveActiveRecurring(array $contactIds): bool {
    if (empty($contactIds)) {
      return FALSE;
    }
    $recurringCount = civicrm_api3('ContributionRecur', 'getCount', [
      'contact_id' => [
        'IN' => $contactIds
      ],
      'contribution_status_id' => [
        'IN' => ['Completed', 'Pending', 'In Progress']
      ],
    ]);
    return $recurringCount > 0;
  }

}

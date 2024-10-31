<?php

use Civi\Api4\EOYEmail;
use Civi\Api4\Exception\EOYEmail\NoEmailException;
use Civi\Api4\Exception\EOYEmail\ParseException;
use CRM_WmfThankyou_ExtensionUtil as E;
use CRM_Sendannualtyemail_AnnualThankYou as AnnualThankYou;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Sendannualtyemail_Form_SendEmail extends CRM_Core_Form {
  private int $contactID;

  public function preProcess(): void {
    $this->contactID = (int) CRM_Utils_Request::retrieve('cid', 'Int', $this, TRUE);
    CRM_Core_Resources::singleton()->addVars('coreForm', ['contact_id' => (int) $this->contactID]);
  }

  /**
   * Rendered message.
   *
   * @var array
   */
  protected array $message;

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function buildQuickForm(): void {
    // add form elements
    $this->add(
      'select',
      'year',
      'Year',
      $this->getYearOptions(),
      FALSE,
    );

    $this->addDatePickerRange('date_range', 'Specify date range');
    $this->setDefaults([
      'date_range_relative' => 'previous.year',
    ]);
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'class' => 'email-send-submit',
        'isDefault' => TRUE,
      ],
    ]);

    $this->setMessage();
    parent::buildQuickForm();
  }

  /**
   * Set the rendered message property, if possible.
   */
  protected function setMessage(): void {
    $this->assign('isEmailable', TRUE);
    try {
      $this->message = EOYEmail::render()
        ->setContactID($this->getContactID())
        ->setYear(date('Y') - 1)
        ->execute()->first();
      $this->assign('subject', $this->message['subject']);
      $this->assign('message', $this->message['html']);
    }
    catch (ParseException $e) {
      $this->assign('isEmailable', FALSE);
      $this->assign('errorText', ts('The letter text could not be rendered for this contact.')
        . ' ' . ts('Take note of any special characters in the contact\'s name and log a phab task'));
    }
    catch (NoEmailException $e) {
      $this->assign('isEmailable', FALSE);
      $this->assign('errorText', ts('This contact does not have a usable email - no end of year letter can be sent.'));
    }
    catch (CRM_Core_Exception $e) {
      // No contributions for the contact last year - don't set the default
      // or do any pre-rendering.
      $this->assign('subject', 'Preview unavailable for selected year');
      $this->assign('message', $e->getMessage());
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function postProcess(): void {
    $sent = Civi\Api4\EOYEmail::send(FALSE)
      ->setContactID($this->getContactID())
      ->setStartDateTime($this->getStartDate())
      ->setEndDateTime($this->getEndDate())
      ->execute()->first();
    if ($sent['sent']) {
      CRM_Core_Session::setStatus('An email with all contributions from ' . date('Y-m-d H:i:s', strtotime($this->getStartDate())) . ' to ' . date('Y-m-d H:i:s', strtotime($this->getEndDate() ?: 'now')) . '  has been sent.');
    }
    else {
      CRM_Core_Session::setStatus('Unable to send email as contributions not found from  ' . date('Y-m-d H:i:s', strtotime($this->getStartDate())) . ' to ' . date('Y-m-d H:i:s', strtotime($this->getEndDate() ?: 'now')));
    }
    parent::postProcess();
  }

  public function getYearOptions(): array {
    $options = [
      '' => E::ts('- select -'),
    ];
    $current_year = date('Y');
    $last_seven_years = array_reverse(range($current_year - 7, $current_year));
    foreach ($last_seven_years as $year) {
      $options[$year] = $year;
    }
    return $options;
  }

  private function getStartDate() {
    if ($this->isRelativeDate()) {
      $dateParts = explode('.', $this->getSubmittedValue('date_range_relative'));
      $dateRange = \CRM_Utils_Date::relativeToAbsolute($dateParts[0], $dateParts[1]);
      return $dateRange['from'];
    }
    return $this->getSubmittedValue('date_range_low');
  }

  private function getEndDate() {
    if ($this->isRelativeDate()) {
      $dateParts = explode('.', $this->getSubmittedValue('date_range_relative'));
      $dateRange = \CRM_Utils_Date::relativeToAbsolute($dateParts[0], $dateParts[1]);
      return $dateRange['to'];
    }
    return $this->getSubmittedValue('date_range_high');
  }

  private function isRelativeDate(): bool {
    return (bool) $this->getSubmittedValue('date_range_relative');
  }

  /**
   * @return int
   */
  public function getContactID(): int {
    return $this->contactID;
  }

}

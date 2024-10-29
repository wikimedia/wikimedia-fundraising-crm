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
      TRUE,
      [
        'onChange' => "CRM.api4('EOYEmail', 'render', {
  contactID: " . $this->getContactID() . ",
  year: this.value
}).then(function(results) {
  for (key in results) {z
     CRM.$('#eoy_message_message').html(results[key]['html']);
     CRM.$('#eoy_message_subject').html(results[key]['subject']);
     break;
  }

}, function(failure) {
  CRM.$('#eoy_message_message').html(failure['error_message']);
});",
      ]);
    $this->setDefaults(['year' => date('Y') - 1]);
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'class' => 'email-send-submit',
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
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
    // send_email
    AnnualThankYou::send($this->getContactID(), $this->getSubmittedValue('year'));
    CRM_Core_Session::setStatus('An email with all contributions from ' . $this->getSubmittedValue('year') . '  will be sent to this contact if they contributed that year.');
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

  /**
   * Set form defaults.
   *
   * @return array
   */
  public function setDefaultValues(): array {
    if (!empty($this->message)) {
      return ['year' => date('Y') - 1];
    }
    return [];
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function getContactID(): int {
    return CRM_Utils_Request::retrieveValue('cid', 'Integer', NULL, TRUE);
  }

}

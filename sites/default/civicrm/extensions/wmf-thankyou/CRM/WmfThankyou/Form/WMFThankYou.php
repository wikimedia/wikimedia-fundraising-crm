<?php

use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_WmfThankyou_Form_WMFThankYou extends CRM_Core_Form {

  /**
   * Build basic form.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function buildQuickForm() {
    $contributionID = CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this);
    if (!$contributionID) {
      $this->assign('no_go_reason', E::ts('A valid contribution ID is required'));
      return;
    }

    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID, 'contribution_status_id' => 'Completed']);
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contribution['contact_id']]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $this->assign('no_go_reason', E::ts('A valid contribution ID is required'));
      return;
    }

    if ($contact['on_hold'] || !$contact['email']) {
      $this->assign('no_go_reason', E::ts('A usable email is required'));
      return;
    }
    $preferredLanguageString = CRM_Core_PseudoConstant::getLabel('CRM_Contact_BAO_Contact', 'preferred_language', $contact['preferred_language']);
    $this->assign('language', $preferredLanguageString ?? $contact['preferred_language']);
    $this->assign('contact', $contact);
    $this->assign('contribution', $contribution);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Send'),
        'isDefault' => TRUE,
      ],
    ]);
    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  /**
   * Submit form.
   *
   * @throws \CRM_Core_Exception
   */
  public function postProcess() {
    try {
      civicrm_api3('Thankyou', 'send', ['contribution_id' => CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this)]);
      CRM_Core_Session::setStatus('Message sent', E::ts('Thank you Sent'), 'success');
    }
    catch (CiviCRM_API3_Exception $e ) {
      CRM_Core_Session::setStatus('Message failed with error ' . $e->getMessage());
    }
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
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

}

<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_LCD_MoveContrib_Form_MoveContrib extends CRM_Core_Form {

  /**
   * check permissions
   */
  public function preProcess(): void {
    //check for delete
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to access this page.'));
    }
    parent::preProcess();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    $contributionID = CRM_Utils_Request::retrieve('id', 'Positive', $this);

    $contactID = civicrm_api3('contribution', 'getvalue', [
      'id' => $contributionID,
      'return' => 'contact_id',
    ]);

    //get current contact name.
    $this->assign('currentContactName', CRM_Contact_BAO_Contact::displayName($contactID));

    $this->addEntityRef('change_contact_id', ts('Select Contact'), [], TRUE);
    $this->add('hidden', 'contact_id', '', ['id' => 'contact_id']);
    $this->add('hidden', 'contribution_id', $contributionID, ['id' => 'contribution_id']);
    $this->add('hidden', 'current_contact_id', $contactID, ['id' => 'current_contact_id']);
    $this->assign('contactId', $contactID);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ],
    ]);

    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    //Civi::log()->debug('postProcess', array('values' => $values));

    $params = [
      'change_contact_id' => $values['change_contact_id'],
      'contact_id' => $values['change_contact_id'],
      'contribution_id' => $values['contribution_id'],
      'current_contact_id' => $values['current_contact_id'],
    ];

    $result = CRM_LCD_MoveContrib_BAO_MoveContrib::moveContribution($params);

    if ($result) {
      CRM_Core_Session::setStatus(ts('Contribution moved successfully.'), ts('Moved'), 'success');
    }
    else {
      CRM_Core_Session::setStatus(ts('Unable to move contribution.'), ts('Error'), 'error');
    }

    parent::postProcess();
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

}

<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2017
 */

/**
 * This class provides the functionality to delete a group of contributions.
 *
 * This class provides functionality for the actual deletion.
 */
class CRM_LCD_MoveContrib_Form_Task extends CRM_Contribute_Form_Task {

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess(): void {
    //check for delete
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to access this page.'));
    }
    parent::preProcess();
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm(): void {
    $this->addEntityRef('change_contact_id', ts('Select Contact'), [], TRUE);
    $count = count($this->_contributionIds);
    $this->assign('count', $count);

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

  /**
   * Process the form after the input has been submitted and validated.
   */
  public function postProcess(): void {
    $moved = $failed = 0;
    $values = $this->exportValues();
    //Civi::log()->debug('postProcess', array('values' => $values));

    foreach ($this->_contributionIds as $contributionId) {
      try {
        $currentContactId = civicrm_api3('Contribution', 'getvalue', [
          'id' => $contributionId,
          'return' => 'contact_id',
        ]);
      }
      catch (CRM_Core_Exception $e) {
      }

      $params = [
        'change_contact_id' => $values['change_contact_id'],
        'contact_id' => $values['change_contact_id'],
        'contribution_id' => $contributionId,
        'current_contact_id' => $currentContactId,
      ];

      if (CRM_LCD_MoveContrib_BAO_MoveContrib::moveContribution($params)) {
        $moved++;
      }
      else {
        $failed++;
      }
    }

    if ($moved) {
      CRM_Core_Session::setStatus(ts('%count contribution moved.', [
        'plural' => '%count contributions moved.',
        'count' => $moved,
      ]), ts('Moved'), 'success');
    }

    if ($failed) {
      CRM_Core_Session::setStatus(ts('1 could not be moved.', [
        'plural' => '%count could not be moved.',
        'count' => $failed,
      ]), ts('Error'), 'error');
    }

    parent::postProcess();
    if (!CRM_Utils_Request::retrieveValue('task_item', 'String') === 'move') {
      $session = CRM_Core_Session::singleton();
      CRM_Utils_System::redirect($session->readUserContext());
    }
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

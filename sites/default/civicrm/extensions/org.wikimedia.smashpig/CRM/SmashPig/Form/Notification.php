<?php

use CRM_SmashPig_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_SmashPig_Form_Notification extends CRM_Core_Form {
  private $notification;
  private $type;

  public function preProcess() {
      parent::preProcess();
      $this->type = $this->getNotificationType();
      $this->assign( 'notification', $this->renderNotification($this->type) );
  }

    //add preprocess and postprocess
  public function buildQuickForm() {

    // add form elements
    $this->addElement(
      'hidden', // field type
      'type', // field name
      $this->getNotificationType()

    );
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Send'),
        'class' => 'notification-send-submit',
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ]
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
      $results = \Civi\Api4\FailureEmail::send()
          ->setContributionRecurID($this->getEntityId())
          ->execute();
      // if not error
      CRM_Core_Session::setStatus("Email sent.");
      parent::postProcess();
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
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  private function renderNotification( $type ) {
      if ($type == 'recurringfailure') {
          $results = \Civi\Api4\FailureEmail::render()
              ->setContributionRecurID( $this->getEntityId() )
              ->execute();
           // assume only one result
          return $results->first();
      }
  }

  private function getEntityId() {
      return CRM_Utils_Request::retrieve('entity_id', 'Int', $this);
  }

  private function getNotificationType() {
    return CRM_Utils_Request::retrieve('type', 'String');
  }
}

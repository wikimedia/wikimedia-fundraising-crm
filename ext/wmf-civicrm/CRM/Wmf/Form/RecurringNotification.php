<?php

use Civi\Api4\Email;
use CRM_Wmf_ExtensionUtil as E;
use Civi\Api4\Message;

/**
 * Form to preview and send recurring message template emails.
 */
class CRM_Wmf_Form_RecurringNotification extends CRM_Core_Form {

  /**
   * Details of the html, text & subject of the email to send.
   *
   * @var array
   */
  private $notification;

  /**
   * Details of the html, text & subject of the email not yet approved.
   *
   * @var array
   */
  private $qaNotification;

  private $workflow;

  public function preProcess() {
    parent::preProcess();
    $this->workflow = $this->getNotificationWorkflow();
    $this->notification = $this->renderNotification($this->workflow);
    $this->assign('notification', $this->notification);
  }

  public function buildQuickForm() {
    $this->addElement(
      'hidden',
      'workflow',
      $this->getNotificationWorkflow()
    );
    $buttons = [];
    if ($this->notification) {
      $buttons[] = [
        'type' => 'submit',
        'name' => E::ts('Send'),
        'class' => 'notification-send-submit',
        'isDefault' => TRUE,
      ];
    }
    if ($this->qaNotification) {
      $buttons[] = [
        'type' => 'submit',
        'name' => E::ts('Send myself a copy'),
        'class' => 'qanotification-send-submit',
        'isDefault' => TRUE,
      ];
      $buttons[] = [
        'type' => 'upload',
        'name' => E::ts('Approve text for automated use'),
        'class' => 'qanotification-approve-submit',
        'isDefault' => TRUE,
      ];
    }
    $buttons[] = [
      'type' => 'cancel',
      'name' => ts('Cancel'),
    ];
    $this->addButtons($buttons);
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    if ($this->notification) {
      \Civi\Api4\FailureEmail::send()
        ->setContributionRecurID($this->getEntityId())
        ->setWorkflow($this->workflow)
        ->execute();
      CRM_Core_Session::setStatus("Email sent.");
      parent::postProcess();
    }
    else {
      if ($this->controller->getActionName()[1] === 'submit') {
        $email = Email::get()
          ->setCheckPermissions(FALSE)
          ->addWhere('contact_id', '=', CRM_Core_Session::getLoggedInContactID())
          ->addWhere('on_hold', '=', 0)
          ->addWhere('email', '<>', '')
          ->setSelect(['email', 'contact.display_name'])
          ->addOrderBy('is_primary', 'DESC')
          ->execute()->first();
        [$domainEmailName, $domainEmailAddress] = \CRM_Core_BAO_Domain::getNameAndEmail();
        $params = [
          'html' => $this->qaNotification['msg_html'] ?? NULL,
          'text' => $this->qaNotification['msg_text'] ?? NULL,
          'subject' => $this->qaNotification['msg_subject'],
          'toEmail' => $email['email'],
          'toName' => $email['display_name'],
          'from' => "$domainEmailName <$domainEmailAddress>",
        ];
        \CRM_Utils_Mail::send($params);
        CRM_Core_Session::setStatus(ts('Mail sent'));
      }
      if ($this->controller->getActionName()[1] === 'upload') {
        Message::updatefromdraft()
          ->setWorkflowName($this->workflow)
          ->setLanguage($this->qaNotification['language'])
          ->execute();
      }
    }
  }

  public function getRenderableElementNames() {
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

  private function renderNotification($workflow) {
    if ($workflow === 'recurring_failed_message' || $workflow === 'recurring_second_failed_message') {
      $results = \Civi\Api4\FailureEmail::render()
        ->setContributionRecurID($this->getEntityId())
        ->setWorkflow($workflow)
        ->execute();
      return $results->first();
    }
  }

  private function getEntityId() {
    return CRM_Utils_Request::retrieve('entity_id', 'Int', $this);
  }

  private function getNotificationWorkflow() {
    return CRM_Utils_Request::retrieve('workflow', 'String');
  }

}

<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\MessageTemplate;
use Civi\Api4\WorkflowMessage;
use CRM_Wmf_ExtensionUtil as E;

/**
 * Generic form to preview and send a workflow message email for a recurring contribution.
 *
 * This could be extended to OTG thank you emails or other emails.
 *
 * URL params:
 *   workflow - symbolic workflow name
 *   crid - ContributionRecur ID
 *   activity_source_record_id - optional source record ID, activity only created if set
 */
class CRM_Wmf_Form_WorkflowMessagePreviewAndSend extends CRM_Core_Form {

  /**
   * @var int|null
   */
  protected ?int $contributionRecurID = NULL;

  /**
   * @var int|null
   */
  protected ?int $contactID = NULL;

  /**
   * @var string|null
   */
  protected ?string $workflow = NULL;

  /**
   * @var int|null
   */
  protected ?int $activitySourceRecordID = NULL;

  public function buildQuickForm(): void {
    if (!$this->getWorkflow() || !$this->getContributionRecurID()) {
      $this->assign('no_go_reason', E::ts('a workflow name and recurring contribution id are required.'));
      return;
    }

    $recur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $this->getContributionRecurID())
      ->addSelect('contact_id', 'contact_id.display_name', 'contact_id.preferred_language', 'contact_id.email_primary.on_hold', 'contact_id.email_primary.email')
      ->execute()->first();
    if (empty($recur)) {
      $this->assign('no_go_reason', E::ts('recurring contribution was not found.'));
      return;
    }
    if ($recur['contact_id.email_primary.on_hold'] || empty($recur['contact_id.email_primary.email'])) {
      $this->assign('no_go_reason', E::ts('a not on hold email address is required.'));
      return;
    }

    $this->contactID = $recur['contact_id'];
    $this->assign('contact', [
      'display_name' => $recur['contact_id.display_name'],
      'email' => $recur['contact_id.email_primary.email'],
    ]);
    $preferredLanguage = $recur['contact_id.preferred_language'] ?: 'en_US';
    $this->assign('language', CRM_Core_PseudoConstant::getLabel('CRM_Contact_BAO_Contact', 'preferred_language', $preferredLanguage));

    $templateTitle = MessageTemplate::get(FALSE)
      ->addWhere('workflow_name', '=', $this->getWorkflow())
      ->addWhere('is_default', '=', TRUE)
      ->addSelect('msg_title')
      ->execute()->first()['msg_title'];
    $this->assign('templateTitle', $templateTitle);

    $rendered = WorkflowMessage::render(FALSE)
      ->setWorkflow($this->getWorkflow())
      ->setLanguage($preferredLanguage)
      ->setValues(['contributionRecurID' => $recur['id'], 'contactID' => $recur['contact_id']])
      ->execute()->first();
    $this->assign('subject', $rendered['subject']);
    $this->assign('message', $rendered['html']);
    $this->addButtons([['type' => 'submit', 'name' => E::ts('Send'), 'isDefault' => TRUE]]);

    parent::buildQuickForm();
  }

  public function postProcess(): void {
    try {
      $send = WorkflowMessage::send(FALSE)
        ->setContactID($this->contactID)
        ->setWorkflow($this->getWorkflow())
        ->setTemplateParameters(['contributionRecurID' => $this->getContributionRecurID()]);
      if ($this->getActivitySourceRecordID()) {
        $send->setActivitySourceRecordID($this->getActivitySourceRecordID());
      }
      $send->execute();

      CRM_Core_Session::setStatus(E::ts('Message sent.'), E::ts('Email Sent'), 'success');
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Session::setStatus(E::ts('Message failed: %1', [1 => $e->getMessage()]), E::ts('Error'), 'error');
    }
  }

  protected function getWorkflow(): string {
    if (!$this->workflow) {
      $this->workflow = (string) CRM_Utils_Request::retrieve('workflow', 'String', $this);
    }
    return $this->workflow;
  }

  protected function getContributionRecurID(): int {
    if (!$this->contributionRecurID) {
      $this->contributionRecurID = (int) CRM_Utils_Request::retrieve('crid', 'Integer', $this);
    }
    return $this->contributionRecurID;
  }

  protected function getActivitySourceRecordID(): ?int {
    if (!$this->activitySourceRecordID) {
      $this->activitySourceRecordID = CRM_Utils_Request::retrieve('activity_source_record_id', 'Integer', $this);
    }
    return $this->activitySourceRecordID;
  }

}

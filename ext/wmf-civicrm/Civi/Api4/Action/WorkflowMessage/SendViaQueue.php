<?php

namespace Civi\Api4\Action\WorkflowMessage;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class SendViaQueue.
 *
 * Asynchronously render and send a workflow message to a contact, recording an email activity
 *
 * @method $this setTemplateParameters(array $templateParameters) Set parameters for rendering the template.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setActivitySourceContactID(int $activitySourceContactID) Set source contact ID for email activity.
 * @method $this setActivitySourceRecordID(int $activitySourceRecordID) Set source record ID for email activity.
 * @method $this setWorkflow(string $workflow) Set workflow
 * @method $this setQueue(string $queue) Set queue name (default 'email')
 */
class SendViaQueue extends AbstractAction {

  /**
   * An array of parameters to send to the WorkflowMessage::render
   *
   * @var array
   */
  protected $templateParameters = [];

  /**
   * Contact ID
   *
   * @required
   * @var int
   */
  protected $contactID;

  /**
   * Name of the email template
   *
   * @required
   * @var string
   */
  protected $workflow;

  /**
   * Optional source record ID for email activity
   *
   * @var int
   */
  protected $activitySourceRecordID;

  /**
   * Optional source contact ID for email activity
   *
   * @var int
   */
  protected $activitySourceContactID;

  /**
   * Name of the queue
   *
   * @var string
   */
  protected $queue = 'email';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $queue = \Civi::queue($this->queue, [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 3,
      'retry_interval' => 20,
      'error' => 'abort',
    ]);
    $sendParameters = [
      'contactID' => $this->contactID,
      'templateParameters' => $this->templateParameters,
      'workflow' => $this->workflow,
      'checkPermissions' => $this->checkPermissions,
    ];
    if ($this->activitySourceRecordID) {
      $sendParameters['activitySourceRecordID'] = $this->activitySourceRecordID;
    }
    if ($this->activitySourceContactID) {
      $sendParameters['activitySourceContactID'] = $this->activitySourceContactID;
    }
    $queue->createItem(new \CRM_Queue_Task(
      'civicrm_api4_queue',
      ['WorkflowMessage', 'send', $sendParameters],
      'Send email for workflow ' . $this->workflow,
    ), ['weight' => 100]);
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['access CiviCRM'];
  }

}

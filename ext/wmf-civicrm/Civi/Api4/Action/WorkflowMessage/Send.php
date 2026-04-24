<?php


namespace Civi\Api4\Action\WorkflowMessage;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WorkflowMessage;

/**
 * Class Send.
 *
 * Render and send a workflow message to a contact, optionally recording an email activity
 *
 * @method $this setTemplateParameters(array $templateParameters) Set parameters for rendering the template.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setActivitySourceContactID(int $activitySourceContactID) Set source contact ID for email activity.
 * @method $this setActivitySourceRecordID(int $activitySourceRecordID) Set source record ID for email activity.
 * @method $this setWorkflow(string $workflow) Set workflow
 */
class Send extends AbstractAction {

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
   * The activity is only created if this is set.
   *
   * @var int|null
   */
  protected $activitySourceRecordID = NULL;

  /**
   * Optional source contact ID for email activity
   *
   * @var int|null
   */
  protected $activitySourceContactID = NULL;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $contact = Contact::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('id', '=', $this->contactID)
      ->setSelect(['display_name', 'email_primary.email', 'preferred_language'])
      ->execute()->first();

    $rendered = WorkflowMessage::render(FALSE)
      ->setLanguage($contact['preferred_language'])
      ->setValues($this->templateParameters + ['contactID' => $this->contactID])
      ->setWorkflow($this->workflow)
      ->execute()->first();

    // If no template exists just back out.
    if (empty($rendered['html']) && empty($rendered['text'])) {
      return;
    }
    // TODO make these parameters
    list($domainEmailName, $domainEmailAddress) = \CRM_Core_BAO_Domain::getNameAndEmail();
    $params = [
      'html' => $rendered['html'] ?? NULL,
      'text' => $rendered['text'] ?? NULL,
      'subject' => $rendered['subject'],
      'toEmail' => $contact['email_primary.email'],
      'toName' => $contact['display_name'],
      'from' => "$domainEmailName <$domainEmailAddress>",
    ];

    $success = \CRM_Utils_Mail::send($params);
    if ($success && $this->activitySourceRecordID) {
      if (trim($rendered['text'])) {
        $details = trim($rendered['text']);
      } else {
        $details = \CRM_Utils_String::htmlToText($rendered['html']);
      }
      Activity::create()->setCheckPermissions(FALSE)->setValues([
        'target_contact_id' => $this->contactID,
        'source_contact_id' => $this->activitySourceContactID ??
            \CRM_Core_Session::getLoggedInContactID() ??
            $this->contactID,
        'subject' => $this->workflow . ' message: ' . $rendered['subject'],
        'details' => $details,
        'activity_type_id:name' => 'Email',
        'activity_date_time' => 'now',
        'source_record_id' => $this->activitySourceRecordID,
      ])->execute();
    }

    foreach ($rendered as $key => $value) {
      $result[$this->contactID][$key] = $value;
    }
    $result[$this->contactID]['from'] = $params['from'];
    $result[$this->contactID]['send_successful'] = $success;
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['access CiviCRM'];
  }

}

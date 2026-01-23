<?php


namespace Civi\Api4\Action\FailureEmail;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\FailureEmail;
use Civi\Api4\Activity;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method $this setContributionRecurID(int $contributionRecurID) Set recurring ID.
 * @method int getContributionRecurID() Get recurring ID.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method $this setSequenceNumber(int $sequenceNumber) Set sequence number.
 */
class Send extends AbstractAction {

  /**
   * An array of one of more ids for which the html should be rendered.
   *
   * These will be the keys of the returned results.
   *
   * @var int
   */
  protected $contributionRecurID;

  /**
   * Contact ID - this is optional & saves a lookup query if provided.
   *
   * @var int
   */
  protected $contactID;

  /**
   * 1 for first recurring failure notification, 2 for second
   * @var int
   */
  protected $sequenceNumber = 1;

  private const ACTIVITY_TYPES = [
    1 => 'First Recurring Failure Email',
    2 => 'Second Recurring Failure Email'
  ];

  private const WORKFLOWS = [
    1 => 'recurring_failed_message',
    2 => 'recurring_second_failed_message'
  ];

  /**
   * Get the contact ID, doing a DB lookup if required.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getContactID() {
    if (!$this->contactID) {
      // @todo no apiv4 yet for this entity
      $this->contactID = \civicrm_api3('ContributionRecur', 'getvalue', ['return' => 'contact_id', 'id' => $this->getContributionRecurID()]);
    }
    return $this->contactID;
  }

  protected function getWorkflow(): string {
    return self::WORKFLOWS[$this->sequenceNumber];
  }

  protected function getActivityType(): string {
    return self::ACTIVITY_TYPES[$this->sequenceNumber];
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $email = FailureEmail::render()
      ->setCheckPermissions(FALSE)
      ->setContactID($this->getContactID())
      ->setContributionRecurID($this->getContributionRecurID())
      ->setWorkflow($this->getWorkflow())
      ->execute()->first();


    // If no template exists just back out.
    if (empty($email['msg_html']) && empty($email['msg_text'])) {
      return FALSE;
    }
    list($domainEmailName, $domainEmailAddress) = \CRM_Core_BAO_Domain::getNameAndEmail();
    $params = [
      'html' => $email['msg_html'] ?? NULL,
      'text' => $email['msg_text'] ?? NULL,
      'subject' => $email['msg_subject'],
      'toEmail' => $email['email'],
      'toName' => $email['display_name'],
      'from' => "$domainEmailName <$domainEmailAddress>",
    ];
    $success = \CRM_Utils_Mail::send($params);
    if ($success) {
      Activity::create()->setCheckPermissions(FALSE)->setValues([
        'target_contact_id' => $this->getContactID(),
        'source_contact_id' => \CRM_Core_Session::getLoggedInContactID() ?? $this->getContactID(),
        'subject' => $email['msg_subject'],
        'details' => $email['msg_html'],
        'activity_type_id:name' => $this->getActivityType(),
        'activity_date_time' => 'now',
        'source_record_id' => $this->contributionRecurID,
      ])->execute();
    }

    foreach ($email as $key => $value) {
      $result[$this->getContributionRecurID()][$key] = $value;
    }
    $result[$this->getContributionRecurID()]['from'] = $params['from'];
    $result[$this->getContributionRecurID()]['send_successful'] = $success;

  }

}

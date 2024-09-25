<?php


namespace Civi\Api4\Action\RecurUpgradeEmail;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\RecurUpgradeEmail;
use Civi\Api4\Activity;

/**
 * Class Render.
 *
 * Get the content of the upgrade email for the specified contributionRecur ID.
 *
 * @method $this setContributionRecurID(int $contributionRecurID) Set recurring ID.
 * @method int getContributionRecurID() Get recurring ID.
 * @method $this setContactID(int $contactID) Set contact ID.
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

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $email = RecurUpgradeEmail::render()
      ->setCheckPermissions(FALSE)
      ->setContactID($this->getContactID())
      ->setContributionRecurID($this->getContributionRecurID())
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
    if (\CRM_Utils_Mail::send($params)) {
      Activity::create()->setCheckPermissions(FALSE)->setValues([
        'target_contact_id' => $this->getContactID(),
        'source_contact_id' => \CRM_Core_Session::getLoggedInContactID() ?? $this->getContactID(),
        'subject' => 'Recur upgrade message : ' . $email['msg_subject'],
        'details' => $email['msg_html'],
        'activity_type_id:name' => 'Email',
        'activity_date_time' => 'now',
        'source_record_id' => $this->contributionRecurID,
      ])->execute();
    }

    foreach ($email as $key => $value) {
      $result[$this->getContributionRecurID()][$key] = $value;
    }
    $result[$this->getContributionRecurID()]['from'] = $params['from'];

  }

}

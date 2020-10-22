<?php


namespace Civi\Api4\Action\FailureEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use \Civi\Api4\Message;

/**
 * Class RenderQA.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method $this setContributionRecurID(int $contributionRecurID) Set recurring ID.
 * @method int getContributionRecurID() Get recurring ID.
 * @method $this setContactID(int $contactID) Set contact ID.
 */
class RenderQA extends Render {

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function _run(Result $result) {
    $email = Email::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('contact_id', '=', $this->getContactID())
      ->addWhere('on_hold', '=', 0)
      ->addWhere('email', '<>', '')
      ->setSelect(['contact.preferred_language', 'email', 'contact.display_name'])
      ->addOrderBy('is_primary', 'DESC')
      ->execute()->first();

    if (empty($email)) {
      return FALSE;
    }

    $message = Message::renderfromfile()
      ->setCheckPermissions(FALSE)
      ->setEntity('ContributionRecur')
      ->setEntityIDs([$this->getContributionRecurID()])
      ->setLanguage($email['contact.preferred_language'])
      ->setWorkflowName('recurring_failed_message')
      ->execute();

    foreach ($message as $index => $value) {
      $value['email'] = $email['email'];
      $value['display_name'] = $email['contact.display_name'];
      $value['language'] = $email['contact.preferred_language'];
      $result[$index] = $value;
    }

  }

}

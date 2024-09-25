<?php


namespace Civi\Api4\Action\FailureEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\WorkflowMessage;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method $this setContributionRecurID(int $contributionRecurID) Set recurring ID.
 * @method int getContributionRecurID() Get recurring ID.
 * @method $this setContactID(int $contactID) Set contact ID.
 */
class Render extends AbstractAction {

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
    $email = Email::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('contact_id', '=', $this->getContactID())
      ->addWhere('email', '<>', '')
      ->setSelect(['contact_id.preferred_language', 'email', 'contact_id.display_name'])
      ->addOrderBy('is_primary', 'DESC')
      ->execute()->first();

    if (empty($email)) {
      return FALSE;
    }

    $supportedLanguages = $this->getSupportedLanguages();
    if (!empty($email['contact_id.preferred_language'])
      && strpos($email['contact_id.preferred_language'], 'en') !== 0
      && !in_array($email['contact_id.preferred_language'], $supportedLanguages, TRUE)
    ) {
      // Temporary early return for non translated languages while we test them.
      // The goal is to create a template for a bunch of languages - the
      // syntax to create is
      // \Civi\Api\MessageTemplate::create()->setLanguage('fr_FR')
      // fall back not that well thought through yet.
      return FALSE;
    }

    $rendered = WorkflowMessage::render(FALSE)
      ->setLanguage($email['contact_id.preferred_language'])
      ->setValues(['contributionRecurID' => $this->getContributionRecurID(), 'contactID' => $this->getContactID()])
      ->setWorkflow('recurring_failed_message')->execute();

    foreach ($rendered as $index => $value) {
      $value['email'] = $email['email'];
      $value['display_name'] = $email['contact_id.display_name'];
      $value['language'] = $email['contact_id.preferred_language'];
      $value['msg_html'] = $value['html'];
      $value['msg_subject'] = $value['subject'];
      $result[$index] = $value;
    }

  }

  /**
   * @return string[]
   */
  protected function getSupportedLanguages(): array {
    if (!isset(Civi::$statics[__CLASS__]['languages'])) {
      $templateID = Civi\Api4\MessageTemplate::get(FALSE)->addWhere('workflow_name', '=', 'recurring_failed_message')->addSelect('id')->execute()->first()['id'];
      $supportedLanguages = (array) Civi\Api4\Translation::get(FALSE)
        ->setWhere([
          ['entity_id', '=', $templateID],
          ['entity_table', '=', 'civicrm_msg_template'],
          ['status_id:name', '=', 'active'],
        ])->addSelect('language')->execute()->indexBy('language');
      Civi::$statics[__CLASS__]['languages'] = array_keys($supportedLanguages);
    }
    return Civi::$statics[__CLASS__]['languages'];
  }

}

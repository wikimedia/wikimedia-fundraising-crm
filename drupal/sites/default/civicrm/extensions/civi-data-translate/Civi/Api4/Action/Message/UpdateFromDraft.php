<?php


namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\MessageTemplate;
use Civi\Api4\Translation;
use Civi\Token\TokenProcessor;

/**
 * Class UpdateFromDraft.
 *
 * @method $this setWorkflowName(string $messageTemplateID) Set Message Template Name.
 * @method string getWorkflowName() Get Message Template Name.
 * @method $this setLanguage(string $entityIDs) Set language (e.g en_NZ).
 * @method string getLanguage() Get language (e.g en_NZ)
 *
 * Load a message template from the disk location, replacing the existing one.
 */
class UpdateFromDraft extends AbstractAction {

  /**
   * ID of message template.
   *
   * It is necessary to pass this or at least one string.
   *
   * @required
   *
   * @var string
   */
  protected $workflowName;

  /**
   * Language to use.
   *
   * @var string
   */
  protected $language;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   */
  public function _run(Result $result) {
    $translations = MessageTemplate::get()
      ->addWhere('workflow_name', '=', $this->getWorkflowName())
      ->addWhere('is_default', '=', 1)
      ->addSelect('translation.*')
      ->addWhere('translation.status_id:name', '=', 'draft')
      ->addWhere('translation.language', '=', $this->getLanguage())
      ->addJoin('Translation AS translation', 'INNER', [
        'id',
        '=',
        'translation.entity_id'
      ], ['translation.entity_table', '=', '"civicrm_msg_template"'])
      ->execute();
    foreach ($translations as $translation) {
      Translation::update()
        ->addWhere('id', '=', $translation['translation.id'])
        ->setValues(['status_id:name' => 'active'])
        ->execute();
    }
  }
}

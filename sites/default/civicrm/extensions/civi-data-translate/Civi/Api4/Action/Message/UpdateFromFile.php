<?php


namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Token\TokenProcessor;

/**
 * Class UpdateFromFile.
 *
 * @method $this setWorkflowName(string $messageTemplateID) Set Message Template Name.
 * @method string getWorkflowName() Get Message Template Name.
 * @method $this setLanguage(string $entityIDs) Set language (e.g en_NZ).
 * @method string getLanguage() Get language (e.g en_NZ)
 *
 * Load a message template from the disk location, replacing the existing one.
 */
class UpdateFromFile extends AbstractAction {

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
    $path = Civi::settings()->get('civi-data-mailing-template-path') . '/' . $this->getWorkflowName() . '.' . $this->getLanguage();
    $msgHtml = file_get_contents($path . '.html.txt');
    $subject = file_get_contents($path . '.subject.txt');
    $msgText = file_get_contents($path . '.text.txt');
    // It actually stores what we pass it - but we want it to store the same value as on the contact.
    $languageName = \Civi\Api4\OptionValue::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('value', '=', $this->getLanguage())
      ->addSelect('name')->execute()->first()['name'];
    // This should be done by Civi but I hit an error on inserting the Japanese (although
    // this might not be needed as I added table alter statements too.
    \CRM_Core_DAO::executeQuery('SET NAMES utf8mb4');
    $values = array_filter([
      'msg_html' => $msgHtml,
      'subject' => $subject,
      'msg_text' => $msgText,
    ]);
    if (empty($values)) {
      throw new \API_Exception('no available files');
    }

    \Civi\Api4\MessageTemplate::update()->setCheckPermissions(FALSE)
      ->setValues($values)
      ->addWhere('workflow_name',  '=', 'recurring_failed_message')
      ->setLanguage($languageName)->execute();
  }

}

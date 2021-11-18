<?php

namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\MessageTemplate;

/**
 * Class Load.
 *
 * Load a message template - returning a translated variant if one exists.
 *
 * @method $this setWorkflow(string $workflowName) Set workflow Name.
 * @method string getWorkflow() Get workflow Name.
 * @method $this setLanguage(string $language) Set language (e.g en_NZ).
 * @method string getLanguage() Get language (e.g en_NZ)
 * @method $this setMessageSubject(string $messageSubject) Set Message Subject
 * @method string getMessageSubject() Get Message Subject
 * @method $this setMessageHtml(string $messageHtml) Set Message Html
 * @method string getMessageHtml() Get Message Html
 * @method $this setMessageText(string $messageHtml) Set Message Text
 * @method string getMessageText() Get Message Text
 *
 * Get the content of an email for the given template text, rendering tokens.
 */
class Load extends AbstractAction {

  /**
   * String to be returned as the subject.
   *
   * @var string
   */
  protected $messageSubject;

  /**
   * String to be returned as the subject.
   *
   * @var string
   */
  protected $messageText;

  /**
   * String to be returned as the html.
   *
   * @var string
   */
  protected $messageHtml;

  /**
   * Name of the workflow.
   *
   * Equivalent to civicrm_msg_template.workflow.
   *
   * @var string
   */
  protected $workflow;

  /**
   * Language to load.
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
    $this->getTemplate();
    if ($this->getWorkflow()) {
      $strings = MessageTemplate::get(FALSE)
        ->addWhere('workflow_name', '=', $this->getWorkflow())
        ->addWhere('is_default', '=', 1)
        ->addSelect('translation.*')
        ->addWhere('translation.status_id:name', '=', 'active')
        ->addWhere('translation.language', '=', $this->getLanguage())
        ->addJoin('Translation AS translation', 'INNER',
          ['id', '=', 'translation.entity_id'],
          ['translation.entity_table', '=', '"civicrm_msg_template"']
        )
        ->execute();
      if (!count($strings)) {
        // For English, or unknown language, we can fall back on the 'main' version of the template.
        if (!$this->getLanguage() || strpos($this->getLanguage(), 'en_') === 0) {
          $strings = MessageTemplate::get(FALSE)
            ->addWhere('workflow_name', '=', $this->getWorkflow())
            ->addWhere('is_default', '=', 1)
            ->setSelect(['msg_html', 'msg_text', 'msg_subject'])
            ->execute()->first();
          $this->setMessageHtml($strings['msg_html']);
          $this->setMessageText($strings['msg_text']);
          $this->setMessageSubject($strings['msg_subject']);
          $result[] = $this->getTemplate();
          return;
        }
        throw new \API_Exception('No translation found');
      }
      foreach ($strings as $string) {
        if ($string['translation.entity_field'] === 'msg_html') {
          $this->setMessageHtml($string['translation.string']);
        }
        if ($string['translation.entity_field'] === 'msg_text') {
          $this->setMessageText($string['translation.string']);
        }
        if ($string['translation.entity_field'] === 'msg_subject') {
          $this->setMessageSubject($string['translation.string']);
        }
      }
    }
    $result[] = $this->getTemplate();
  }

  /**
   * Get the template.
   *
   * @return array
   */
  protected function getTemplate(): array {
    return [
      'msg_html' => ['string' => $this->getMessageHtml(), 'format' => 'text/html', 'key' => 'msg_html'],
      'msg_subject' => ['string' => trim($this->getMessageSubject()), 'format' => 'text/plain', 'key' => 'msg_subject'],
      'msg_text' => ['string' => $this->getMessageText(), 'format' => 'text/plain', 'key' => 'msg_text'],
    ];
  }

}

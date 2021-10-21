<?php


namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\MessageTemplate;
use Civi\Token\TokenProcessor;

/**
 * Class RenderFromDraft
 */
class RenderFromDraft extends Render {

  /**
   * Load the relevant message template.
   *
   * @throws \API_Exception
   */
  protected function loadMessageTemplate():void {
    // The locale reverts back when the variable is destructed.
    $swapLocale = \CRM_Utils_AutoClean::swapLocale($this->getLanguage());

    if ($this->getWorkflowName()) {
      $strings = MessageTemplate::get()
        ->addWhere('workflow_name', '=', $this->getWorkflowName())
        ->addWhere('is_default', '=', 1)
        ->addSelect('translation.*')
        ->addWhere('translation.status_id:name', '=', 'draft')
        ->addWhere('translation.language', '=', $this->getLanguage())
        ->addJoin('Translation AS translation', 'INNER', ['id', '=', 'translation.entity_id'], ['translation.entity_table', '=', '"civicrm_msg_template"'])
        ->execute();
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
  }

}

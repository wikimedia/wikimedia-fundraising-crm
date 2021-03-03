<?php


namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Token\TokenProcessor;

/**
 * Class RenderFromFile
 */
class RenderFromFile extends Render {

  /**
   * Load the relevant message template.
   */
  protected function loadMessageTemplate() {
    if ($this->getWorkflowName()) {
      $path = Civi::settings()->get('civi-data-mailing-template-path') . '/' . $this->getWorkflowName() . '.' . substr($this->getLanguage(), 0, 2);
      $this->setMessageHtml(file_get_contents($path . '.html.txt'));
      $this->setMessageSubject(file_get_contents($path . '.subject.txt'));
      $this->setMessageText(file_get_contents($path . '.text.txt'));
    }
  }

}

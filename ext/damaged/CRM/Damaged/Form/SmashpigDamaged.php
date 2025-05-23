<?php

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Damaged_Form_SmashpigDamaged extends CRM_Core_Form {
  protected $_id;
  protected $_message;
  protected $messageFields = [];
  protected $damagedRow;
  protected $_trace = '';
  protected $deleteMessage = "Are you sure you want to delete this damaged message row from the database?";

  private function check_plain($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }
  private function setDamagedMessage(): self {
    try {
      $this->_message = $this->damagedRow->getMessage();
    } catch (CRM_Core_Exception $exception ) {
      CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
    }
    return $this;
  }

  private function setTrace(): void {
    $trace = $this->damagedRow->getTrace();
    $this->_trace = str_replace(
      "\n", '<br/>',  $this->check_plain($trace)
    );
  }

  private function setDamagedRow($id): CRM_Damaged_Form_SmashpigDamaged {
    $params = ['id' => $id];
    $damagedRow = null;
    CRM_Damaged_BAO_Damaged::retrieve($params, $damagedRow);
    if (empty($damagedRow)) {
      throw new CRM_Core_Exception('Damaged message does not exist or has been resent to the corresponding queue.');
    }
    $this->damagedRow = new CRM_Damaged_DamagedRow($damagedRow);
    return $this;
  }

  /**
   * This function is called prior to building and submitting the form
   */
  public function preProcess(): void {
    // Perform any setup tasks you may need
    // often involves grabbing args from the url and storing them in class variables
    CRM_Core_Resources::singleton()->addStyleFile('civicrm', 'css/damaged.css', 1, 'html-header');
    try {
      $this->_id = CRM_Utils_Request::retrieve('id', 'String');
      if (empty($this->_id) && !empty($this->_submitValues) && !empty($this->_submitValues["entryURL"])) {
        $query = [];
        $parts = parse_url($this->_submitValues["entryURL"]);
        if ( isset( $parts['query'] ) ) {
          parse_str( htmlspecialchars_decode($parts['query']), $query );
          if (isset($query['id'])) {
            $this->_id = $query['id'];
          } else if (isset($query['amp;id'])) {
            $this->_id = $query['amp;id'];
          }
        }
      }
    } catch (CRM_Core_Exception $exception ) {
      CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
    }
  }

  /**
   * Set message fields to be assigned to the form.
   */
  protected function setMessageFields(): CRM_Damaged_Form_SmashpigDamaged {
    foreach($this->_message as $key=>$value){
      $fieldSpec = [
        'name' => $key,
      ];
      $this->messageFields[$key] = $fieldSpec;
      try {
        $this->add('text', $key, ts($key));
      } catch (CRM_Core_Exception $exception ) {
        CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
      }
    }

    return $this;
  }

  /**
   * Explicitly declare the form context.
   */
  public function getDefaultContext(): string {
    return 'create';
  }

    /**
   * Explicitly declare the entity api name.
   */
  public function getDefaultEntity(): string {
    return 'Damaged';
  }


  public function buildQuickForm(): void {
    try {
      $buttons = array([
        "type" => "submit",
        "name" => "Resend",
        "isDefault" => TRUE
      ]);
      if ($this->_action & CRM_Core_Action::DELETE) {
        $buttons = array([
          "type" => "submit",
          "name" => "Delete",
        ]);
        $this->setTitle("Delete damaged message");
        $this->assign('deleteMessage', $this->deleteMessage);
      } else {
        $this->applyFilter('__ALL__', 'trim');
        $this->setDamagedRow($this->_id)
        ->setDamagedMessage()
        ->setMessageFields()
        ->setTrace();
        $this->setTitle("Examine damaged message");
        $this->assign('trace', $this->_trace);
        $this->assign('error', $this->damagedRow->getError());
        $this->assign('messageFields', $this->messageFields);
      }
      $this->addButtons($buttons);
      parent::buildQuickForm();
    } catch (CRM_Core_Exception $exception) {
      $error_message = "Invalid damaged row ID";
      if (!empty($this->_id)) {
        $error_message = "Damaged message with ID: $this->_id does not exist.";
      }
      $this->setTitle($error_message);
      CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
    }
  }


  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * @return array
   */
  public function setDefaultValues(): ?array {
    if ($this->_action !== CRM_Core_Action::DELETE &&
      isset($this->_id)
    ) {
      return $this->_message;
    }
      return parent::setDefaultValues();
  }

  /**
   * Process the form submission.
   */
  public function postProcess(): void {
    if ($this->_action & CRM_Core_Action::DELETE) {
      try {
        CRM_Damaged_BAO_Damaged::del($this->_id);
        CRM_Core_Session::setStatus(ts('Selected Damaged message has been deleted.'), ts('Record Deleted'), 'success');
      }
      catch (CRM_Core_Exception $exception) {
        CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
      }
    }
    else {
      $damagedRow = $this->damagedRow;

      CRM_SmashPig_ContextWrapper::createContext('damaged_message_form');
      // store the submitted values in an array
      $updatedMessage = $this->exportValues();
      unset($updatedMessage['setQfKey'],
        $updatedMessage['qfKey'],
        $updatedMessage['entryURL'],
        $updatedMessage['_qf_default'],
        $updatedMessage['_qf_SmashpigDamaged_submit']);
  
      $damagedRow->setMessage($updatedMessage);
      try {
        CRM_Damaged_BAO_Damaged::pushObjectsToQueue($damagedRow);
        CRM_Core_Session::setStatus(ts('Message %1 resent for processing.', array( 1 => $damagedRow->getId() ) ), ts('Saved'), 'success');
        CRM_Damaged_BAO_Damaged::del($damagedRow->getId());
        $this->assign('trace', '');
        $this->assign('error', '');
        $this->assign('messageFields', '');
        $this->setTitle("Message $this->_id has been resent for processing");
      }
      catch (CRM_Core_Exception | JsonException $exception ) {
        CRM_Core_Session::setStatus($exception->getMessage(), ts('Error'), 'error');
      }
    }
  }
}

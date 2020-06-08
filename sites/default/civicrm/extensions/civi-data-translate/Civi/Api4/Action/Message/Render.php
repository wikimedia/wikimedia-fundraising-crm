<?php


namespace Civi\Api4\Action\Message;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Token\TokenProcessor;

/**
 * Class Render.
 *
 * Get the content of an email for the given template text, rendering tokens.
 *
 * @method $this setWorkflowName(string $messageTemplateID) Set Message Template Name.
 * @method string getWorkflowName() Get Message Template Name.
 * @method $this setMessageSubject(string $messageSubject) Set Message Subject
 * @method string getMessageSubject() Get Message Subject
 * @method $this setMessageHtml(string $messageHtml) Set Message Html
 * @method string getMessageHtml() Get Message Html
 * @method $this setMessageText(string $messageHtml) Set Message Text
 * @method string getMessageText() Get Message Text
 * method array getMessages() Get array of adhoc strings to parse. See explanation in getStringsToParse for why this is currently commented out.See explanation in getStringsToParse for why this is currently commented out.
 * method $this setMessages(array $stringToParse) Set array of adhoc strings to parse. See explanation in getStringsToParse for why this is currently commented out.
 * @method $this setEntity(string $entity) Set entity.
 * @method string getEntity() Get entity.
 * @method $this setEntityIDs(array $entityIDs) Set entity IDs
 * @method array getEntityIDs() Get entity IDs
 * @method $this setWhere(array $whereClauses) Set where clauses.
 * @method $this setLanguage(string $entityIDs) Set language (e.g en_NZ).
 * @method string getLanguage() Get language (e.g en_NZ)
 * @method $this setLimit(int $limit) Set Limit
 * @method int getLimit() Get Limit
 */
class Render extends AbstractAction {

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
   * Ad hoc html strings to parse.
   *
   * See explanation in getStringsToParse for why this is currently commented out.
   *
   * Array of adhoc strings arrays to pass e.g
   *  [
   *    ['string' => 'Dear {contact.first_name}', 'format' => 'text/html', 'key' => 'greeting'],
   *    ['string' => 'Also known as {contact.first_name}', 'format' => 'text/plain', 'key' => 'nick_name'],
   * ]
   *
   * If no provided the key will default to 'string' and the format will default to 'text'
   *
   * @var array
  protected $messages = [];
   */

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
   * String to be returned as the subject.
   *
   * @var string
   */
  protected $messageHtml;

  /**
   * Entity for which tokens need to be resolved.
   *
   * This is required if tokens related to the entity are to be parsed and the entity cannot
   * be derived from the message_template.
   *
   * Entities currently limited as it bears thinking about how we best map entities to the workflow templates.
   *
   * @var string
   *
   * @required
   *
   * @options Contribution,ContributionRecur
   */
  protected $entity;

  /**
   * An array of one of more ids for which the html should be rendered.
   *
   * These will be the keys of the returned results.
   *
   * @var array
   */
  protected $entityIDs = [];

  /**
   * Where clause to pass to entity retrieval.
   *
   * Set this, or set entityIDs. EntityIDs will be converted to a where clause.
   *
   * @var array
   */
  protected $where;

  /**
   * Language to use.
   *
   * @var string
   */
  protected $language;

  /**
   * Limit of entities to process.
   *
   * @var int
   */
  protected $limit;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   */
  public function _run(Result $result) {
    $this->loadMessageTemplate();
    $tokenProcessor = new TokenProcessor(Civi::dispatcher(), [
      'controller' => __CLASS__,
      // Permissions implications in not-false. Also a preference to simply do smarty parsing after
      // in a wrapper function when we go there.
      'smarty' => FALSE,
      'schema' => [$this->getEntity() => $this->getEntityKey()],
      'language' => $this->getLanguage(),
    ]);

    // Use wrapper as we don't know which entity.
    // Doing a get here allows us to do permission checking which is not obviously present in the token processor.
    // It also helps with the fact some entities don't have processors. Note that it's not ideal to do select * but...
    // otherwise we need to know the tokens.
    $entities = \civicrm_api4($this->getEntity(), 'get', ['where' => $this->getWhere(), 'select' => ['*'], 'checkPermissions' => $this->checkPermissions, 'limit' => $this->getLimit()]);

    foreach ($entities as $entity) {
      foreach ($this->getStringsToParse() as $fieldKey => $textField) {
        if (empty($textField['string'])) {
          continue;
        }
        if (!empty($entity['contact_id'])) {
          // @todo - we have checked permissions on the entity values we pass in to our processor but the
          // contact token processor also does swapsies. Managing risk for now by limiting to workflow templates.
          $tokenProcessor->addRow()->context(array_merge(['contactId' => $entity['contact_id'], 'entity' => $this->getEntity()], $entity));
        }
        else {
          $tokenProcessor->addRow()->context();
        }

        $tokenProcessor->addMessage($fieldKey, $textField['string'], $textField['format']);
        $tokenProcessor->evaluate();
        foreach ($tokenProcessor->getRows() as $row) {
          /* @var \Civi\Token\TokenRow $row */
          $result[$entity['id']][$fieldKey] = $row->render($fieldKey);
        }
      }
    }
  }

  /**
   * Get the relevant where clause.
   *
   * @return array
   */
  public function getWhere(): array {
    if (!empty($this->entityIDs)) {
      $this->where[] = ['id', 'IN', $this->entityIDs];
    }
    return $this->where;
  }

  /**
   * Array holding
   *  - string String to parse, required
   *  - key Key to key by in results, defaults to 'string'
   *  - format - format passed to token providers.
   *
   * @param array $stringDetails
   *
   * @return \Civi\Api4\Action\Message\Render
   */
  public function addMessage(array $stringDetails): Render {
    $this->messages[] = $stringDetails;
    return $this;
  }

  /**
   * Get the strings to render civicrm tokens for.
   *
   * @return array
   */
  protected function getStringsToParse(): array {
    $textFields = [
      'msg_html' => ['string' => $this->getMessageHtml(), 'format' => 'text/html', 'key' => 'msg_html'],
      'msg_subject' => ['string' => $this->getMessageSubject(), 'format' => 'text/plain', 'key' => 'msg_subject'],
      'msg_text' => ['string' => $this->getMessageText(), 'format' => 'text/plain', 'key' => 'msg_text'],
    ];
    /*
     The intention was to also allow ad hoc strings. However, some security need to be thought through.
     Although we do a security check on the ability to access the main entity it is not clear that
     there is a permission check on contact fields associated with a contact, so this could be a bypass
     to get custom fields otherwise security-restricted.
     Hence we limit to message templates, the  creation of which implies some security access.
    foreach ($this->getMessages() as $message) {
      $message['key']  = $message['key'] ?? 'string';
      $message['format'] = $message['format'] ?? 'text/plain';
      $textFields[$message['key']] = $message;
    }
    */
    return $textFields;
  }

  /**
   * Get the key to use for the entity ID field.
   *
   * @return string
   */
  protected function getEntityKey(): string {
    return strtolower($this->getEntity()) . 'Id';
  }

  /**
   * Load the relevant message template.
   */
  protected function loadMessageTemplate() {
    if ($this->getWorkflowName()) {
      $messageTemplate = \Civi\Api4\MessageTemplate::get()
        ->setLanguage($this->getLanguage())
        ->addWhere('workflow_name', '=', $this->getWorkflowName())
        ->addWhere('is_active', '=', TRUE)
        ->addWhere('is_default', '=', TRUE)
        ->setSelect(['*'])
        // I think we want to check permissions - but only render permissioned.
        ->setCheckPermissions(FALSE)
        ->execute()->first();
      $this->setMessageHtml($messageTemplate['msg_html']);
      $this->setMessageText($messageTemplate['msg_text']);
      $this->setMessageSubject($messageTemplate['msg_subject']);
    }
  }

}

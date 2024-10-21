<?php


namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WorkflowMessage;

/**
 * Class Render.
 *
 * Get the content of the thank you.
 *
 * @method array getTemplateParameters() Get the parameters for the template.
 * @method $this setTemplateParameters(array $templateParameters) Get the parameters for the template.
 * @method string getTemplateName() Get the name of the template.
 * @method $this setTemplateName(string $templateName) Get the name of the template.
 * @method $this setContributionID(int $contributionID)
 * @method $this setLanguage(string $language) Set the language to render in.
 * @method $this setReceiveDate(string $receiveDate) Set the receiveData to UTC contribution received date.
 */
class Render extends AbstractAction {

  /**
   * Parameters for the template.
   *
   * @var array
   */
  protected $templateParameters = [];

  /**
   * The contact's language.
   *
   * This might already be in a 2 character variant but should
   * cope (once refactored) with the value stored on the contact.
   *
   * @var string
   */
  protected $language;

  /**
   * @var int
   */
  protected $contributionID;

  /**
   * The name of the selected template.
   *
   * Options are thank_you, endowment_thank_you, monthly_convert.
   *
   * @var string
   */
  protected $templateName;

  public function getContributionID() {
    return $this->contributionID ?: $this->getTemplateParameters()['contribution_id'];
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $locale = $this->getLanguage();
    $templateParams = $this->getTemplateParameters();

    // TemplateParams['locale'] holds the mediawiki-style locale.
    // It is used for
    // 1 - a template parameter for adding locale to a url
    // 2 - loading the template - this usage will be removed once
    // we move the templates to the database (at which point users can edit too).
    $templateParams['locale'] = strtolower(str_replace('_', '-', $locale));

    $rendered = WorkflowMessage::render(FALSE)
      ->setLanguage($this->getLanguage())
      ->setValues($this->getModelProperties())
      ->setWorkflow($this->getTemplateName())->execute()->first();
    $html = $rendered['html'];
    $subject = $rendered['subject'];
    $page_content = str_replace('<p></p>', '', $html);
    $result[] = [
      'html' => $page_content,
      'subject' => trim($subject),
    ];
  }

  /**
   * Get the language to render in.
   *
   * @return string eg. en_US
   */
  public function getLanguage(): string {
    if (!$this->language) {
      Civi::log('wmf')->info('Donor language unknown.  Defaulting to English...', ['language' => $this->language]);
      $this->language = 'en_US';
    }
    return $this->language;
  }

  /**
   * Get the properties for the WorkflowMessage data model.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getModelProperties(): array {
    $properties = [];
    $properties['contact'] = $this->getContactParameters();
    $properties['contribution'] = $this->getContributionParameters();
    // I've hard-coded this list to ensure anything we are passing that is not handled
    // bubbles up - but in time we might not need this whole wrapper class.
    $mapping = [
      'locale' => 'shortLocale',
      'language' => 'locale',
      'day_of_month' => 'dayOfMonth',
      'recipient_address' => 'email',
      'recurring' => 'isRecurring',
      'transaction_id' => 'transactionID',
    ];
    foreach ($this->getTemplateParameters() as $fieldName => $value) {
      if (isset($mapping[$fieldName])) {
        $properties[$mapping[$fieldName]] = $value;
      }
    }
    // The contributionID is the minimum viable properties.
    $properties['contributionID'] = $this->getContributionID();
    return $properties;
  }

  /**
   * Get the passed in contact parameters.
   *
   * Passing these on prevents a db lookup.
   *
   * @return array
   */
  protected function getContactParameters(): array {
    $parameters = [];
    foreach ($this->getContactParameterMapping() as $contactField => $incomingKey) {
      if ($this->getTemplateParameter($incomingKey) !== NULL) {
        $parameters[$contactField] = $this->getTemplateParameter($incomingKey);
      }
    }
    return $parameters;
  }

  /**
   * Get the passed in contact parameters.
   *
   * Passing these on prevents a db lookup.
   *
   * @return array
   */
  protected function getContributionParameters(): array {
    $parameters = [];
    foreach ($this->getContributionParameterMapping() as $contributionKey => $incomingKey) {
      if ($this->getTemplateParameter($incomingKey) !== NULL) {
        $parameters[$contributionKey] = $this->getTemplateParameter($incomingKey);
      }
    }
    return $parameters;
  }

  protected function getTemplateParameter($parameter) {
    return $this->getTemplateParameters()[$parameter] ?? NULL;
  }

  /**
   * @return string[]
   */
  protected function getContactParameterMapping(): array {
    return [
      'first_name' => 'first_name',
      'last_name' => 'last_name',
      'contact_type' => 'contact_type',
      'email_greeting_display' => 'email_greeting_display',
      'id' => 'contact_id',
      'organization_name' => 'organization_name',
      'External_Identifiers.venmo_user_name' => 'venmo_user_name',
    ];
  }

  /**
   * @return string[]
   */
  protected function getContributionParameterMapping(): array {
    return [
      'receive_date' => 'receive_date',
      'trxn_id' => 'trxn_id',
      'id' => 'contribution_id',
      'payment_instrument_id' => 'payment_instrument_id',
      'contribution_extra.gateway' => 'gateway',
      'contribution_extra.original_amount' => 'amount',
      'contribution_extra.original_currency' => 'currency',
      'contribution_recur.frequency_unit' => 'frequency_unit',
      'Stock_Information.Description_of_Stock' => 'description_of_stock',
      'Stock_Information.Stock Value' => 'stock_value',
      'Stock_Information.Stock Ticker' => 'stock_ticker',
      'Stock_Information.Stock Quantity' => 'stock_qty',
      'Gift_Data.Campaign' => 'gift_source',
    ];
  }

}

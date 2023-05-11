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

    $templateParams['gift_source'] = $templateParams['gift_source'] ?? NULL;
    $templateParams['stock_value'] = $templateParams['stock_value'] ?? NULL;

    if ($templateParams['stock_value']) {
      $templateParams['stock_value'] = Civi::format()
        ->money($templateParams['stock_value'], $templateParams['currency']);
    }
    else {
      $templateParams['stock_value'] = 0;
    }

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
      'unsubscribe_link' => 'unsubscribeLink',
      'gift_source' => 'giftSource',
      'stock_value' => 'stockValue',
      'description_of_stock' => 'descriptionOfStock',
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
    return array_filter([
      'first_name' => $this->getTemplateParameters()['first_name'] ?? NULL,
      'last_name' => $this->getTemplateParameters()['last_name'] ?? NULL,
      'contact_type' => $this->getTemplateParameters()['contact_type'] ?? NULL,
      'email_greeting_display' => $this->getTemplateParameters()['email_greeting_display'] ?? NULL,
      'id' => !empty($this->getTemplateParameters()['contact_id']) ? (int) $this->getTemplateParameters()['contact_id'] :  NULL,
      'organization_name' => $this->getTemplateParameters()['organization_name'] ?? '',
    ]);
  }

  /**
   * Get the passed in contact parameters.
   *
   * Passing these on prevents a db lookup.
   *
   * @return array
   */
  protected function getContributionParameters(): array {
    return array_filter([
      'receive_date' => $this->getTemplateParameters()['receive_date'] ?? NULL,
      'trxn_id' => $this->getTemplateParameters()['trxn_id'] ?? NULL,
      'id' => $this->getTemplateParameters()['contribution_id'] ?? NULL,
      'total_amount' => $this->getTemplateParameters()['amount'] ?? NULL,
      'currency' => $this->getTemplateParameters()['currency'] ?? NULL,
    ]);
  }

}

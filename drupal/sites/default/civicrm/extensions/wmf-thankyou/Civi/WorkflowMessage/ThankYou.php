<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\EntityTag;
use CRM_Core_PseudoConstant;

/**
 * This is the template class for previewing WMF end of year thank you emails.
 *
 * @support template-only
 *
 * @method string getContactType()
 * @method $this setContactType(string $contactType)
 * @method $this setContributionID(int $contributionID)
 * @method int getContributionID()
 * @method string getEmail()
 * @method $this setShortLocale(string $shortLocale)
 * @method $this setEmail(string $email)
 * @method $this setAmount(string|int|float $amount)
 * @method $this setCurrency(string $currency)
 * @method string getCurrency()
 * @method string getReceiveDate()
 * @method $this setIsRecurring(bool $isRecurring)
 * @method bool getIsRecurring()
 * @method $this setIsRecurringRestarted(bool $isRecurringRestarted)
 * @method $this setIsDelayed(bool $isDelayed)
 * @method $this setStockValue(int|float|string $stockValue)
 * @method $this setStockQuantity(int $stockQuantity)
 * @method $this setStockTicker(string $stockTicker)
 * @method $this setDescriptionOfStock(string $descriptionOfStock)
 * @method string getDescriptionOfStock()
 * @method $this setGiftSource(string $giftSource)
 * @method string getGiftSource()
 * @method $this setTransactionID(string $transactionID)
 * @method $this setVenmoUserName(?string $venmoUserName)
 * @method int getPaymentInstrumentID()
 * @method int setPaymentInstrumentID(int $paymentInstrumentID)
 * @method string getGateway()
 * @method $this setGateway(string $gateway)
 * @method string getEmailGreetingDisplay()
 * @method $this setEmailGreetingDisplay(string $emailGreetingDisplay)
 * @method string getFrequencyUnit()
 * @method $this setFrequencyUnit(string $frequencyUnit)
 */
class ThankYou extends GenericWorkflowMessage {
  use UnsubscribeTrait;

  public const WORKFLOW = 'thank_you';

  /**
   * Contribution gateway.
   *
   * @var string
   */
  public $gateway;

  /**
   * Contribution Payment Instrument ID for payment method.
   *
   * @var int
   */
  public $paymentInstrumentID;

  /**
   * Contribution ID.
   *
   * @var int
   */
  public $contributionID;

  /**
   * Amount of contribution.
   *
   * At this stage it is being set already formatted here.
   *
   * @var string
   *
   * @scope tplParams
   */
  public $amount;

  /**
   * Contribution currency.
   *
   * @var string
   *
   * @scope tplParams
   */
  public $currency;

  /**
   * Date received.
   *
   * @var string
   *
   * @scope tplParams as receive_date
   */
  public $receiveDate;

  /**
   * Contact's individual name.
   *
   * @var string
   *
   * @scope tplParams as first_name
   */
  public $firstName;

  /**
   * Contact's family name.
   *
   * @var string
   *
   * @scope tplParams as last_name
   */
  public $lastName;

  /**
   * @var string
   */
  public $email;

  /**
   * Contact Type.
   *
   * @var string
   *
   * @scope tplParams as contact_type
   */
  public $contactType;

  /**
   * @var array
   */
  public array $contribution = [];

  /**
   * Email greeting display.
   *
   * @var string
   *
   * @scope tplParams as email_greeting_display
   */
  public $emailGreetingDisplay;

  /**
   * Frequency unit (for recurring contributions).
   *
   * @var string
   *
   * @scope tplParams as frequency_unit
   */
  public $frequencyUnit;

  /**
   * @var string
   *
   * @scope tplParams as organization_name
   */
  public $organizationName;

  /**
   * Locale that can be used in MediaWiki URLS (eg. en or fr-FR).
   *
   * @var string
   *
   * @scope tplParams as locale
   */
  public $shortLocale;

  /**
   * Value of in kind stock donation.
   *
   * @var float|int|null
   *
   * @scope tplParams as stock_value
   */
  public $stockValue;

  /**
   * Quantity of stock gifted.
   *
   * @var float|int|null
   *
   * @scope tplParams as stock_quantity
   */
  public $stockQuantity;

  /**
   * Ticker of stock gifted - eg. 'AAPL' for Apple stock.
   *
   * @var string|null
   *
   * @scope tplParams as stock_ticker
   */
  public $stockTicker;

  /**
   * Description of in kind stock donation.
   *
   * @var string
   *
   * @scope tplParams as description_of_stock
   */
  public $descriptionOfStock = '';

  /**
   * Gift Source.
   *
   * @var string
   *
   * @scope tplParams as gift_source
   */
  public $giftSource = '';

  /**
   * Is this a recurring contribution.
   *
   * @var bool
   *
   * @scope tplParams as recurring
   */
  public $isRecurring = FALSE;

  /**
   * @var string
   *
   * @scope tplParams as transaction_id
   */
  public $transactionID;

  /**
   * If donate with venmo, retrieve username for additional info
   *
   * @var string
   *
   * @scope tplParams as venmo_user_name
   */
  public $venmoUserName;

  /**
   * Have we restarted the recurring due to a technical issue.
   *
   * @var bool
   *
   * @scope tplParams
   */
  public $isRecurringRestarted;

  /**
   * Has the notification been delayed due to a technical issue.
   *
   * @var bool
   *
   * @scope tplParams
   */
  public $isDelayed;

  /**
   * @var float
   */
  private $totalAmount;

  /**
   * Set contribution object.
   *
   * @param array $contribution
   *
   * @return $this
   */
  public function setContribution(array $contribution): self {
    $this->contribution = $contribution;
    if (!empty($contribution['id'])) {
      $this->contributionID = $contribution['id'];
      foreach ($this->getContributionParameters() as $key => $property) {
        if (!empty($contribution[$key]) && empty($this->$property)) {
          $method = 'set' . ucfirst($property);
          $value = ($key === 'contribution_recur_id') ? (bool) $contribution[$key] : $contribution[$key];
          $this->$method($value);
        }
      }
    }
    return $this;
  }

  public function setReceiveDate($receiveDate): self {
    // Format the datestamp
    $date = strtotime($receiveDate);

    // For tax reasons, any donation made in the US on Jan 1 UTC should have a time string in HST.
    // So do 'em all that way.
    $this->receiveDate = date('Y-m-d', $date - (60 * 60 * 10));
    return $this;
  }

  /**
   * Get the relevant contribution, loading it as required.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContribution(): array {
    $missingKeys = array_diff_key($this->getContributionParameters(), $this->contribution);
    if ($missingKeys) {
      $this->setContribution(Contribution::get(FALSE)
        ->setSelect(array_keys($this->getContributionParameters()))
        ->addWhere('id', '=', $this->getContributionID())
        ->execute()->first() ?? []);
    }
    return $this->contribution;
  }

  private function getContributionParameters(): array {
    return [
      'total_amount' => 'totalAmount',
      'id' => 'contributionID',
      'contact_id' => 'contactID',
      'receive_date' => 'receiveDate',
      'payment_instrument_id' => 'paymentInstrumentID',
      'contribution_extra.gateway' => 'gateway',
      'contribution_extra.original_currency' => 'currency',
      'contribution_extra.original_amount' => 'amount',
      'Stock_Information.Description_of_Stock' => 'descriptionOfStock',
      'Stock_Information.Stock Value' => 'stockValue',
      'Stock_Information.Stock Ticker' => 'stockTicker',
      'Stock_Information.Stock Quantity' => 'stockQuantity',
      'Gift_Data.Campaign' => 'giftSource',
      'contribution_recur_id' => 'isRecurring',
      'contribution_recur.frequency_unit' => 'frequencyUnit',
    ];
  }

  /**
   * Get the relevant contribution, loading it as required.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContact(): array {
    if (!$this->contact) {
      $this->setContact(Contact::get(FALSE)
        ->setSelect(array_keys($this->getContactParameters()))
        ->addWhere('id', '=', $this->getContactID())
        ->execute()->first());
    }
    return $this->contact;
  }

  /**
   * Get the contact parameters to be used.
   *
   * @return string[]
   */
  private function getContactParameters(): array {
    return [
      'contact_type' => 'contactType',
      'first_name' => 'firstName',
      'last_name' => 'lastName',
      'email_greeting_display' => 'emailGreetingDisplay',
      'email_primary.email' => 'email',
      'preferred_language' => 'locale',
      'organization_name' => 'organizationName',
      'External_Identifiers.venmo_user_name' => 'venmoUserName',
    ];
  }

  /**
   * Set contact object.
   *
   * @param array $contact
   *
   * @return $this
   */
  public function setContact(array $contact): self {
    $this->contact = $contact;
    if (!empty($contact['id'])) {
      $this->contactID = $contact['id'];
    }
    foreach ($this->getContactParameters() as $key => $property) {
      if (!empty($contact[$key]) && empty($this->$property)) {
        $this->$property = $contact[$key];
      }
    }
    return $this;
  }

  /**
   * @var array
   */
  protected $tags;

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getTags() : array {
   if ($this->tags === NULL) {
     $this->tags = (array) EntityTag::get(FALSE)
       ->addWhere('entity_table', '=', 'civicrm_contribution')
       ->addWhere('entity_id', '=', $this->getContributionID())
       ->addWhere('tag_id:name', 'IN', ['RecurringRestarted', 'UnrecordedCharge'])
       ->addSelect('tag_id:name')
       ->execute()->indexBy('tag_id:name');
   }
   return $this->tags;
  }

  /**
   * Has the contribution been tagged as a re-started recurring.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function getIsRecurringRestarted(): bool {
    return $this->isRecurringRestarted ?? !empty($this->getTags()['RecurringRestarted']);
  }

  /**
   * Has the contribution been tagged as a re-started recurring.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function getIsDelayed(): bool {
    return $this->isDelayed ?? !empty($this->getTags()['UnrecordedCharge']);
  }

  /**
   * Get the transaction ID.
   *
   * This is just contact ID with a string in front.
   *
   * @return string
   */
  public function getTransactionID(): string {
    return $this->transactionID ?? 'CNTCT-' . $this->contactID;
  }

  /**
   * if current donation is venmo, which via braintree not fundraiseUp will return username
   *
   * @return ?string
   */
  public function getVenmoUserName(): ?string {
    if (($this->gateway == 'braintree' && CRM_Core_PseudoConstant::getName(
      'CRM_Contribute_BAO_Contribution', 'payment_instrument_id', $this->paymentInstrumentID) === 'Venmo')
    || (!$this->gateway && !$this->paymentInstrumentID))
    {
      return $this->venmoUserName;
    }
    else {
      return null;
    }
  }

  /**
   * Get the short locale required for media wiki use - e.g 'en'
   */
  public function getShortLocale(): string {
    return $this->shortLocale ?: substr((string) $this->getLocale(), 0, 2);
  }

  /**
   * Get amount, this formats if not already formatted.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getAmount(): string {
    if (!$this->amount) {
      $this->amount = $this->getContribution()['contribution_extra.original_amount'];
    }
    // We only want to format it once - and we want to do that once we know the
    // 'resolved' locale - ie it resolves to email in en_US for someone whose
    // locale is en_GB. Once the requestedLocale is set then locale will
    // be the resolved locale. This means that we get the same formatting for USD 5
    // for everyone getting the email in English.
    // @see https://wikitech.wikimedia.org/wiki/Fundraising/Internal-facing/CiviCRM#Money_formatting_in_emails
    if (!is_numeric($this->amount) || !$this->getRequestedLocale()) {
      return (string) $this->amount;
    }
    return \Civi::format()->money($this->amount, $this->currency, $this->locale);
  }

  public function setTotalAmount(float $totalAmount) : self {
    $this->totalAmount = $totalAmount;
    if (empty($this->amount)) {
      // getAmount returns a formatted amount but it can cope with an
      // unformatted one. We don't want to try to format before currency is set.
      $this->setAmount($totalAmount);
    }
    return $this;
  }

  /**
   * Get amount, this formats if not already formatted.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getStockValue(): string {
    if (!is_numeric($this->stockValue)) {
      return (string) $this->stockValue;
    }
    if ((int) $this->stockValue === 0) {
      return '';
    }
    return \Civi::format()->money($this->stockValue, $this->currency);
  }

  /**
   * Ensures that 'name' can be retrieved from the token, if not set.
   *
   * @param array $export
   */
  protected function exportExtraTokenContext(array &$export): void {
    $export['smartyTokenAlias']['first_name'] = 'contact.first_name';
    $export['smartyTokenAlias']['last_name'] = 'contact.last_name';
    $export['smartyTokenAlias']['contact_type'] = 'contact.contact_type';
    $export['smartyTokenAlias']['email_greeting_display'] = 'contact.email_greeting_display';
  }

}

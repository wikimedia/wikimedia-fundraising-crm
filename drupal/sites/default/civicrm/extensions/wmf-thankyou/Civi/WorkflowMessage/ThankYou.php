<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Exception\EOYEmail\NoContributionException;

/**
 * This is the template class for previewing WMF end of year thank you emails.
 *
 * @support template-only
 *
 * @method array getContact()
 * @method $this setContact(array $contact)
 * @method $this setContactID(int $contactID)
 * @method $this setAmount(string|int|float $amount)
 * @method $this setCurrency(string $currency)
 * @method string getCurrency()
 * @method $this setReceiveDate(string $receiveDate)
 * @method string getReceiveDate()
 * @method $this setIsRecurring(bool $isRecurring)
 * @method bool getIsRecurring()
 * @method $this setIsRecurringRestarted(bool $isRecurringRestarted)
 * @method bool getIsRecurringRestarted()
 * @method $this setIsDelayed(bool $isDelayed)
 * @method bool getIsDelayed()
 * @method $this setStockValue(int|float|string $stockValue)
 * @method $this setDescriptionOfStock(string $descriptionOfStock)
 * @method string getDescriptionOfStock()
 * @method $this setGiftSource(string $giftSource)
 * @method string getGiftSource()
 * @method $this setTransactionID(string $transactionID)
 */
class ThankYou extends GenericWorkflowMessage {
  public const WORKFLOW = 'thank_you';

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
   * Contact Type.
   *
   * @var string
   *
   * @scope tplParams as contact_type
   */
  public $contactType;

  /**
   * Email greeting display.
   *
   * @var string
   *
   * @scope tplParams as email_greeting_display
   */
  public $emailGreetingDisplay;

  /**
   * Locale that can be used in MediaWiki URLS (eg. en or fr-FR).
   *
   * @var string
   *
   *  @scope tplParams
   */
  public $locale;

  /**
   * Value of in kind stock donation.
   *
   * @var float|int|null
   *
   * @scope tplParams as stock_value
   */
  public $stockValue;

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
   * @var array
   */
  public $contribution_tags;

  /**
   * @var string
   *
   * @scope tplParams as transaction_id
   */
  public $transactionID;

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
   * @var string
   */
  public $unsubscribe_link;

  /**
   * Get the transaction ID.
   *
   * This is just contact ID with a string in front.
   *
   * @return string
   */
  public function getTransactionID(): string {
    return $this->transactionID ?? 'CNTCT-' . $this->contactId;
  }

  /**
   * Get amount, this formats if not already formatted.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getAmount(): string {
    if (!is_numeric($this->amount)) {
      return $this->amount;
    }
    return \Civi::format()->money($this->amount, $this->currency);
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
    $export['smartyTokenAlias']['email_greeting_display'] = 'email_greeting_display';
  }

}

<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\EntityTag;

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
 * @method $this setDescriptionOfStock(string $descriptionOfStock)
 * @method string getDescriptionOfStock()
 * @method $this setGiftSource(string $giftSource)
 * @method string getGiftSource()
 * @method $this setTransactionID(string $transactionID)
 * @method $this setUnsubscribeLink(string $unsubscribeLink)
 * @method $this getEmailGreetingDisplay(string $emailGreetingDisplay)
 * @method $this setEmailGreetingDisplay(string $emailGreetingDisplay)
 */
class ThankYou extends GenericWorkflowMessage {
  public const WORKFLOW = 'thank_you';

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
  public $contribution;

  /**
   * Email greeting display.
   *
   * @var string
   *
   * @scope tplParams as email_greeting_display
   */
  public $emailGreetingDisplay;

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
   *
   * @scope tplParams as unsubscribe_link
   */
  public $unsubscribeLink;

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
          $this->$method($contribution[$key]);
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
    $this->receiveDate =  strftime('%Y-%m-%d', $date - (60 * 60 * 10));
    return $this;
  }

  /**
   * Get the relevant contribution, loading it as required.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContribution(): array {
    if (!$this->contribution) {
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
      'contribution_extra.original_currency' => 'currency',
      'contribution_extra.original_amount' => 'amount',
      'Stock_Information.Description_of_Stock' => 'descriptionOfStock',
      'Stock_Information.Stock Value' => 'stockValue',
      'Gift_Data.Campaign' => 'giftSource',
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
   * Get the short locale required for media wiki use - e.g 'en'
   */
  public function getShortLocale(): string {
    return $this->shortLocale ?: substr($this->getLocale(), 0, 2);
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
    if (!is_numeric($this->amount)) {
      return $this->amount;
    }
    return \Civi::format()->money($this->amount, $this->currency);
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
   * Get the unsubscribe link.
   *
   * Note this is likely to be passed in from thank-you
   * at the moment but really it is enough to do it here & we can remove
   * the other function. We might need to set up a WMFWorkflowTrait
   * to share it though. However, will do that soon...
   *
   * @return string
   */
  public function getUnsubscribeLink(): string {
    return $this->unsubscribeLink ?: \Civi::settings()->get('wmf_unsubscribe_url') . '?' . http_build_query([
      'p' => 'thankyou',
      'c' => $this->getContributionID(),
      'e' => $this->getEmail(),
      'h' => sha1($this->getContributionID() . $this->getEmail() . \CRM_Utils_Constant::value('WMF_UNSUB_SALT')),
      'uselang' => $this->getShortLocale(),
    ]);
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

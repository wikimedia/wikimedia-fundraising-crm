<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Exception\EOYEmail\NoContributionException;

/**
 * This is the template class for previewing WMF end of year thank you emails.
 *
 * Medium term this will move to the extension but the first release of the
 * new code (which we are currently on) only loads these from within
 * the CiviCRM core codebase - hence it is living here until early 2022.
 *
 * @support template-only
 * @method array getContact()
 * @method $this setContact(array $contact)
 * @method $this setActiveRecurring(bool $activeRecurring)
 * @method $this setCancelledRecurring(bool $cancelledRecurring)
 * @method $this setContactIDs(array $contactIDs)
 * @method $this setContributions(array $contributions)
 * @method $this setIsValidDonorName(bool $isValidDonorName)
 * @method array setTotals()
 * @method $this setYear(?int $year)
 */
class EOYThankYou extends GenericWorkflowMessage {
  public const WORKFLOW = 'eoy_thank_you';

  /**
   * The contact.
   *
   * @var array|null
   *
   * @scope tokenContext
   *
   * @required
   */
  public $contact;

  /**
   * Contact IDs.
   *
   * @var array
   */
  public $contactIDs;

  /**
   * The year to send emails for.
   *
   * @var int
   * @scope tplParams
   */
  public $year;

  /**
   * The start date-time to send emails for.
   *
   * @var string
   * @scope tplParams
   *
   * @required
   */
  public string $startDateTime = '';

  /**
   * The end date-time to send emails for.
   *
   * @var string
   * @scope tplParams
   *
   * @required
   */
  public string $endDateTime = '';

  /**
   * Should the start and end date be shown.
   *
   * @var bool
   * @scope tplParams
   */
  public bool $isShowStartAndEndDates = FALSE;

  public function getIsShowStartAndEndDates(): bool {
    if ($this->isShowStartAndEndDates) {
      return TRUE;
    }
    if ($this->year) {
      return FALSE;
    }
    return $this->startDateTime || $this->endDateTime;
  }

  public function getStartDateTime(): string {
    if ($this->startDateTime) {
      return $this->startDateTime;
    }
    if ($this->getYear()) {
      return $this->getYear() . '-01-01 10:00:00';
    }
    return '';
  }

  public function getYear(): string {
    if ($this->year) {
      return $this->year;
    }
    $lastYear = date('Y') - 1;
    if (date('Ymd', strtotime($this->startDateTime)) === ($lastYear . '0101')
      &&
      (date('Ymd', strtotime($this->endDateTime)) === $lastYear . '1231')
    ) {
      return $lastYear;
    }
    return '';
  }

  /**
   * Get the start date for querying purposes.
   *
   * They want to know since the beginning of time... or at least since the beginning of our database.
   * Let's use 2000 as a stand in cos nothing really happened in the
   * universe before then.
   *
   * @return string
   */
  private function getQueryStartDateTime(): string {
    return $this->getStartDateTime() ?: '2000-01-01';
  }

  /**
   * Get the end date for querying purposes.
   *
   * Currently this is this is the same as the display date as
   * I made an executive decision people would want to see when their
   * letters cover up to - but that might be replaced by a real decision
   * in future so it seemed worth keeping the symetry.
   *
   * @return string
   */
  private function getQueryEndDateTime(): string {
    return $this->getEndDateTime();
  }

  public function getEndDateTime(): string {
    if ($this->endDateTime) {
      return $this->endDateTime;
    }
    if ($this->getYear()) {
      return ($this->getYear() + 1) . '-01-01 10:00:00';
    }
    // It is open-ended - but
    // not into the future...
    return date('Y-m-d 23:59:59');
  }

  /**
   * Contributions to be receipted.
   *
   * @var array
   * @scope tplParams
   *
   * @required
   */
  public $contributions;

  /**
   * Does this donor have active recurring contributions.
   *
   * @var bool
   * @scope tplParams as active_recurring
   *
   * @required
   */
  public $activeRecurring;

  /**
   * Does this donor have cancelled recurring contributions.
   *
   * @var bool
   * @scope tplParams as cancelled_recurring
   *
   * @required
   */
  public $cancelledRecurring;

  /**
   * Does this donor have any endowment contributions in the period.
   *
   * @var bool
   * @scope tplParams
   */
  public $hasEndowment;

  /**
   * Does this donor have any non-endowment contributions in the period.
   *
   * @var bool
   * @scope tplParams
   */
  public $hasAnnualFund;

  /**
   * Does this donor have valid first name and last name
   *
   * @var bool
   * @scope tplParams
   */
  public $isValidDonorName;

  /**
   * @var \DateTimeZone
   */
  private $britishTime;

  /**
   * @var \DateTimeZone
   */
  private $beachTime;

  /**
   * Does the donor have any endowment donations in the period.
   *
   * @return bool
   */
  public function getHasEndowment(): bool {
    if ($this->hasEndowment === NULL) {
      $this->hasEndowment = FALSE;
      foreach ($this->contributions as $contribution) {
        if ($contribution['financial_type_id:name'] === 'Endowment Gift') {
          $this->hasEndowment = TRUE;
        }
      }
    }
    return $this->hasEndowment;
  }

  /**
   * Does the donor have any annual fund donations.
   *
   * @return bool
   */
  public function getHasAnnualFund(): bool {
    if ($this->hasAnnualFund === NULL) {
      $this->hasAnnualFund = FALSE;
      foreach ($this->contributions as $contribution) {
        if ($contribution['financial_type_id:name'] !== 'Endowment Gift') {
          $this->hasAnnualFund = TRUE;
        }
      }
    }
    return $this->hasAnnualFund;
  }

  /**
   * if valid first and last name
   *
   * @return bool
   */
  public function getIsValidDonorName(): bool {
    if ($this->isValidDonorName === NULL) {
      $this->isValidDonorName = FALSE;
      $contact = Contact::get(FALSE)
        ->addSelect('first_name', 'last_name')
        ->addWhere('id', '=', $this->contactID)
        ->execute()->first();
      if ($contact['first_name'] && $contact['last_name']) {
        $this->isValidDonorName = TRUE;
      }
    }
    return $this->isValidDonorName;
  }

  /**
   * Totals,by locale,currency, to be receipted.
   *
   * @var array
   * @scope tplParams
   *
   * @required
   */
  public $totals;

  /**
   * @throws \CRM_Core_Exception
   */
  public function getTotals(): array {
    $locale = $this->getLocale();
    if (!empty($this->totals[$locale])) {
      return $this->totals[$locale];
    }
    foreach ($this->getContributions() as $contribution) {
      if (!isset($this->totals[$locale][$contribution['currency']])) {
        $this->totals[$locale][$contribution['currency']] = [
          'amount' => 0.0,
          'currency' => $contribution['currency'],
        ];
      }
      $this->totals[$locale][$contribution['currency']]['amount'] += $contribution['total_amount'];
    }
    foreach ($this->totals[$locale] as $currency => $details) {
      $this->totals[$locale][$currency]['amount'] = \Civi::format()->moneyNumber($details['amount'], $details['currency'], $locale);
    }
    return $this->totals[$locale] ?? [];
  }

  /**
   * Get the array of contributions.
   * Refunds are excluded.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContributions(): array {
    if (!isset($this->contributions)) {
      $this->contributions = (array) Contribution::get(FALSE)
        ->addWhere('contact_id', 'IN', $this->getContactIDs())
        ->addWhere('contribution_status_id:name', '=', 'Completed')
        ->addWhere('financial_type_id:name', '!=', 'Refund')
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->addWhere('receive_date', 'BETWEEN', [
          $this->getQueryStartDateTime(),
          $this->getQueryEndDateTime(),
        ])
        ->addSelect('id', 'contribution_extra.original_currency', 'financial_type_id:name', 'contribution_extra.original_amount', 'contribution_recur_id', 'receive_date', 'total_amount')
        ->addOrderBy('receive_date', 'ASC')
        ->execute();
      if (empty($this->contributions)) {
        throw new NoContributionException('No contributions in the given time from - ' . $this->getQueryStartDateTime() . ' to ' . $this->getQueryEndDateTime() . ' for contact/s ' . implode(',', $this->getContactIDs()));
      }
    }
    if (isset($this->contributions[0])) {
      // The array index is used as the 'contribution number' in the templates
      // so make sure it is numbered from 1.
      array_unshift($this->contributions, []);
      unset($this->contributions[0]);
    }
    foreach ($this->contributions as $index => $contribution) {
      $this->contributions[$index]['currency'] = $contribution['currency'] = $contribution['contribution_extra.original_currency'] ?? 'USD';
      $this->contributions[$index]['total_amount'] = $contribution['total_amount'] = $contribution['contribution_extra.original_amount'] ?? $contribution['total_amount'];
      if (!isset($contribution['financial_type'])) {
        // Smarty can't handle the colon - so we need to put it into another field too.
        $this->contributions[$index]['financial_type'] = $contribution['financial_type_id:name'];
      }
      // We need both the formatted amount and the unformatted. Going with the db field holding the db value
      // and 'amount' holding formatted.
      $this->contributions[$index]['amount'] = \Civi::format()->moneyNumber($contribution['total_amount'], $contribution['currency'], $this->getLocale());
      if (!array_key_exists('date', $contribution)) {
        // We convert to Hawaii time which is 10 hours earlier than
        // GMT to avoid issues with last minute donations slipping into the next year in some timezones,
        // also present as date only.
        $britishTime = $this->getBritishTime();
        $date = date_create($contribution['receive_date'], $britishTime);
        $beachTime = $this->getBeachTime();
        $date->setTimezone($beachTime);
        $this->contributions[$index]['date'] = $date->format('Y-m-d');
        // Reluctantly set 'receive_date' to the same formatted/timezone converted value since
        // we've already pushed out templates with {$contribution.receive_date}
        $this->contributions[$index]['receive_date'] = $this->contributions[$index]['date'];
      }
    }
    return $this->contributions;
  }

  /**
   * Get bool for whether a recurring is active.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getActiveRecurring(): bool {
    if (!isset($this->activeRecurring)) {
      $recurringCount = ContributionRecur::get(FALSE)
        ->addSelect('count')
        ->addWhere('contact_id', 'IN', $this->getContactIDs())
        ->addWhere('contribution_status_id:name', 'IN', ['Pending', 'In Progress'])
        ->execute();
      $this->activeRecurring = count($recurringCount) > 0;
    }
    return $this->activeRecurring;
  }

  /**
   * Get bool for whether a recurring was cancelled this year
   *
   * @throws \CRM_Core_Exception
   */
  protected function getCancelledRecurring(): bool {
    if (!isset($this->cancelledRecurring)) {
      $recurringCount = ContributionRecur::get(FALSE)
        ->addSelect('count')
        ->addWhere('contact_id', 'IN', $this->getContactIDs())
        ->addWhere('contribution_status_id:name', 'IN', ['Cancelled'])
        ->addWhere('cancel_date', 'BETWEEN', [
          $this->getYear() . '-01-01 10:00:00',
          'now',
        ])
        ->execute();
      $this->cancelledRecurring = count($recurringCount) > 0;
    }
    return $this->cancelledRecurring;
  }

  /**
   * Get the array of contributions.
   *
   * @return array
   */
  protected function getContactIDs(): array {
    if (!isset($this->contactIDs)) {
      $this->contactIDs = [$this->contact['id']];
    }
    return $this->contactIDs;
  }

  /**
   * Ensures that 'name' can be retrieved from the token, if not set.
   *
   * @param array $export
   */
  protected function exportExtraTokenContext(array &$export): void {
    $export['smartyTokenAlias']['name'] = 'contact.first_name';
  }

  /**
   * @return \DateTimeZone
   */
  protected function getBritishTime(): \DateTimeZone {
    if (!$this->britishTime) {
      $this->britishTime = new \DateTimeZone('Europe/London');
    }
    return $this->britishTime;
  }

  /**
   * @return \DateTimeZone
   */
  protected function getBeachTime(): \DateTimeZone {
    if (!$this->beachTime) {
      $this->beachTime = new \DateTimeZone('Pacific/Honolulu');
    }
    return $this->beachTime ;
  }

}

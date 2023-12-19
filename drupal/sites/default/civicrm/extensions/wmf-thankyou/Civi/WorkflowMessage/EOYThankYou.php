<?php

namespace Civi\WorkflowMessage;

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
 * @method $this setActive_recurring(bool $active_recurring)
 * @method $this setContactIDs(array $contactIDs)
 * @method $this setContributions(array $contributions)
 * @method array setTotals()
 * @method int getYear()
 * @method $this setYear(int $year)
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
   *
   * @required
   */
  public $year;

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
   * @scope tplParams
   *
   * @required
   */
  public $active_recurring;

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
   * Totals,by locale,currency, to be receipted.
   *
   * @var array
   * @scope tplParams
   *
   * @required
   */
  public $totals;

  /**
   * @throws \API_Exception
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
   * @throws \API_Exception
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
          $this->getYear() . '-01-01 10:00:00',
          ($this->getYear() + 1). '-01-01 10:00:00',
        ])
        ->addSelect('id', 'contribution_extra.original_currency', 'financial_type_id:name', 'contribution_extra.original_amount', 'contribution_recur_id', 'receive_date', 'total_amount')
        ->addOrderBy('receive_date', 'ASC')
        ->execute();
      if (empty($this->contributions)) {
        throw new NoContributionException('No contributions in the given year - ' . $this->getYear() . ' for contact/s ' . implode(',', $this->getContactIDs()));
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
   * @throws \API_Exception
   */
  protected function getActiveRecurring(): bool {
    if (!isset($this->active_recurring)) {
      $recurringCount = ContributionRecur::get(FALSE)
        ->addSelect('count')
        ->addWhere('contact_id', 'IN', $this->getContactIDs())
        ->addWhere('contribution_status_id:name', 'IN', ['Pending', 'In Progress'])
        ->execute();
      $this->active_recurring = count($recurringCount) > 0;
    }
    return $this->active_recurring;
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

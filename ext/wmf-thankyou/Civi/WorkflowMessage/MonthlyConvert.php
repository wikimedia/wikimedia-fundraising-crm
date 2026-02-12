<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\ContributionRecur;

/**
 * This is the template class for previewing WMF monthly convert emails.
 *
 * @support template-only
 */
class MonthlyConvert extends ThankYou {
  use \CRM_Contribute_WorkflowMessage_RecurringTrait;

  public const WORKFLOW = 'monthly_convert';

  /**
   * Has the contribution been tagged as a re-started recurring.
   *
   * This is never true for monthly convert - saves a db lookup.
   *
   * @return bool
   */
  public function getIsRecurringRestarted(): bool {
    return FALSE;
  }

  /**
   * Has the contribution been tagged as a re-started recurring.
   *
   * This is never true for monthly convert - saves a db lookup.
   *
   * @return bool
   */
  public function getIsDelayed(): bool {
    return FALSE;
  }

  /**
   * Day of month, translated.
   *
   * e.g 25th
   *
   * @var string
   *
   * @scope tplParams as day_of_month
   */
  public string $dayOfMonth;

  public function setDayOfMonth(int|string $dayOfMonth) {
    // Format the day of the month as an ordinal number
    $ordinal = new \NumberFormatter($this->getLocale(), \NumberFormatter::ORDINAL);
    $this->dayOfMonth = $ordinal->format($dayOfMonth);
    return $this;
  }

  public function getDayOfMonth() {
    if (!isset($this->dayOfMonth)) {
      $this->setDayOfMonth((new \DateTime($this->getContributionRecur()['start_date'], new \DateTimeZone('UTC')))->format('j'));
    }
    return $this->dayOfMonth;
  }

  /**
   * Get the relevant contribution, loading it as required.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getContributionRecur(): array {
    $missingKeys = array_diff_key(['start_date', 'currency', 'amount'], $this->contributionRecur ?? []);
    if ($missingKeys && isset($this->contributionRecurId)) {
      $this->setContributionRecur(ContributionRecur::get(FALSE)
        ->addWhere('id', '=', $this->contributionRecurId)
        ->execute()->first() ?? []);
    }
    return $this->contributionRecur ?: [];
  }

  /**
   * Get amount, this formats if not already formatted.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getAmount(): string {
    if (!$this->amount) {
      $this->amount = $this->getContributionRecur()['amount'];
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

}

<?php

namespace Civi\WorkflowMessage;

/**
 * This is the template class for previewing WMF monthly convert emails.
 *
 * @support template-only
 */
class MonthlyConvert extends ThankYou {

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
  public $dayOfMonth;

  public function setDayOfMonth(int $dayOfMonth) {
    // Format the day of the month as an ordinal number
    $ordinal = new \NumberFormatter($this->getLocale(), \NumberFormatter::ORDINAL);
    $this->dayOfMonth = $ordinal->format($dayOfMonth);
  }

}

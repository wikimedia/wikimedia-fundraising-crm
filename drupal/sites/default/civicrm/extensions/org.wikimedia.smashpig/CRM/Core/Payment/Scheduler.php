<?php

class CRM_Core_Payment_Scheduler {

  /**
   * @param array $record contribution_recur db record, containing at least:
   *  frequency_interval, cycle_day. frequency_unit is also consulted to see
   *  whether the donation is annual.
   * @param int $nowstamp optional timestamp at which to perform the
   *  calculation, otherwise now()
   *
   * @return string  Returns a date stamp in the format 'Y-m-d H:i:s' =>
   *   2011-12-31 00:00:00
   */
  public static function getNextContributionDate( $record, $nowstamp = NULL) {
    $triggered_for_date = self::getLastTriggerDate($record, $nowstamp);
    // $frequency_interval and $cycle_day will, at this point, have been found in $record.
    $frequency_interval = (integer) $record['frequency_interval'];
    $cycle_day = $record['cycle_day'];

    $scheduled_date_stamp = $triggered_for_date;
    $added = 0;
    while (gmdate('Y-m-d', $triggered_for_date) >= gmdate('Y-m-d', $scheduled_date_stamp) && ($added < $frequency_interval)) {
      // this will happen at least once.
      $scheduled_date_stamp = self::incrementDateToTargetDay(
        $scheduled_date_stamp, $record['frequency_unit'] ?: 'month', $cycle_day
      );
      $added += 1;
    }

    return gmdate('Y-m-d H:i:s', $scheduled_date_stamp);
  }

  /**
   * Calculates the last date this payment should have been triggered for,
   * regardless of the actual date, or the last recorded date in the schedule.
   *
   * @param array $record An array that contains, at least, the cycle day.
   *  Passing this around in record format because that's what the rest of the
   *  module does.
   * @param int|null $nowstamp optional unix timestamp if we are performing the
   * calculation for another date
   *
   * @TODO: Stop passing around the whole record.
   * @return int timestamp A midnight timestamp for the day that should have
   *  triggered this recurring transaction.
   */
  protected static function getLastTriggerDate($record, $nowstamp = NULL) {
    if ($nowstamp === NULL) {
      $nowstamp = time();
    }

    //Instead of adding to now, we have to look for the last time the cycle date
    //should have been triggered, regardless of when the transaction actually went through.

    //TODO: This needs to implement more frequency intervals. For now, though, we only use monthly, so...
    if (!array_key_exists('cycle_day', $record) || !is_numeric($record['cycle_day'])) {
      return $nowstamp;
    }
    else {
      $cycle_day = (integer) $record['cycle_day'];
    }

    $month = (int) gmdate('n', $nowstamp);
    $year = (int) gmdate('Y', $nowstamp);

    // Build a timestamp for the cycle day in this month
    // If we are still in the same month, this will be the correct value. If we're in the next month,
    // it'll be the wrong value and it'll be in the future; we fix that up below.
    $last_trigger = gmmktime(0, 0, 0, $month, self::getCycleDayForMonth($cycle_day, $month), $year);

    // So... we actually want last month's date... to psych out the code which
    // will add a month.  Note that this is not necessarily the true last
    // trigger date, just our best-case guess.
    while ($last_trigger > $nowstamp && ($last_trigger - $nowstamp) > 60 * 60 * 24 * 7) {
      //decrement the month until it was in the past.
      --$month;
      if ($month < 1) {
        $month = 12;
        --$year;
      }
      $last_trigger = gmmktime(0, 0, 0, $month, self::getCycleDayForMonth($cycle_day, $month), $year);
    }
    return $last_trigger;
  }

  /**
   * @param int $date as unix seconds
   *
   * @return int day of the month for this date
   */
  protected static function getCycleDay($date) {
    return intval(gmdate('j', $date));
  }

  /**
   * Increment the $date by one $interval, landing as close as possible to
   * $cycle_day. Have only implemented the $interval of 'month' at this point.
   * Might wire up more later as-needed.
   *
   * @param int $date Timestamp to increment by the interval
   * @param string $interval A name for the interval that we're incrementing.
   * @param int $cycle_day The target day of the month for this payment
   *
   * @return int The $date parameter incremented by one calendar interval.
   */
  protected static function incrementDateToTargetDay($date, $interval = 'month', $cycle_day = NULL) {
    if (is_null($cycle_day)) {
      $cycle_day = self::getCycleDay($date);
    }
    $month = (int) gmdate('n', $date);
    $year = (int) gmdate('Y', $date);

    switch ($interval) { //just making it slightly nicer in here for the next guy
      case 'year':
        return gmmktime(0, 0, 0, $month, self::getCycleDayForMonth($cycle_day, $month), $year + 1);
      case 'month':
      default:
        $month += 1;
        //if we wanted to edit this to handle adding more than one month at
        //a time, we could do some fun stuff with modulo here.
        if ($month > 12) {
          $month = 1;
          $year += 1;
        }

        $target_day = self::getCycleDayForMonth($cycle_day, $month);

        $next_date = gmmktime(0, 0, 0, $month, $target_day, $year);
        return $next_date;
    }
  }

  /**
   * @param int $cycle_day - target day of the month for this subscription
   * @param int $month - target month
   *
   * @return int The day of the specified month most appropriate for the target
   *  cycle day. This will only change if the target day doesn't exist in
   *  certain months.
   */
  protected static function getCycleDayForMonth($cycle_day, $month) {
    $last_day = self::daysInMonth($month);
    if ($cycle_day > $last_day) {
      return $last_day;
    }
    return $cycle_day;
  }

  /**
   * Cheap port of cal_days_in_month, which is not supported in hhvm
   *
   * Ignores the leap year cos we don't care.
   *
   * @param integer $month One-based month number
   *
   * @return int
   */
  protected static function daysInMonth($month) {
    $lookup = [
      '1' => 31,
      '2' => 28,
      '3' => 31,
      '4' => 30,
      '5' => 31,
      '6' => 30,
      '7' => 31,
      '8' => 31,
      '9' => 30,
      '10' => 31,
      '11' => 30,
      '12' => 31,
    ];
    return $lookup[$month];
  }

}

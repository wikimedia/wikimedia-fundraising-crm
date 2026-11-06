<?php

namespace Civi\Api4\Action\WMFDonor;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFDonorHistory;
use Civi\WMFHook\CalculatedData;

/**
 * Rebuild wmf_donor_history by replaying donor data over time.
 *
 * The logged fields are calculated as of each contribution, recurring change,
 * recurring unpause and financial year rollover, with a row whenever one of
 * them changed. Only the source table that changed is recalculated. Each
 * contact's history before the end date is replaced with these rows, which are
 * also returned. With a start date, earlier rows are kept.
 */
class RebuildHistory extends AbstractAction {

  /**
   * Events this close together are calculated as one potential segment/status change.
   *
   * e.g. a recurring payment and the update to its recurring, which is logged
   * when the payment is processed, nearly always within 20 minutes of its
   * receive date.
   */
  public const MERGE_SECONDS = 1200;

  /**
   * IDs of the contacts to rebuild history for.
   *
   * @var array
   * @required
   */
  protected $contactIds = [];

  /**
   * Rebuild history from (including) this SQL datetime. Defaults to all time.
   *
   * @var string
   */
  protected $startDate;

  /**
   * Rebuild history before (not including) this SQL datetime. Defaults to
   * now, including what's logged this second.
   *
   * @var string
   */
  protected $endDate;

  /**
   * The fields logged in wmf_donor_history, by the source table they're calculated from.
   *
   * [tableName => [fieldName => spec]]: the segment and overall and OTG statuses
   * come from civicrm_contribution, the recurring statuses from civicrm_contribution_recur.
   *
   * @var array
   */
  private array $loggedFieldsByTable = [];

  /**
   * Whether the contact being rebuilt has recurrings that started before logging did.
   *
   * @var bool
   */
  private bool $hasPreLogRecurrings = FALSE;

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // Normalised as it's compared as a string.
    $endDate = date('Y-m-d H:i:s', strtotime($this->endDate ?? '+1 second'));
    // Events just before the start date may have been merged with ones from it.
    $startDate = $this->startDate ? date('Y-m-d H:i:s', strtotime($this->startDate) - self::MERGE_SECONDS) : NULL;
    foreach (CalculatedData::createForSourceTables() as $tableName => $processor) {
      $this->loggedFieldsByTable[$tableName] = $processor->getLoggedFields();
    }
    foreach (array_unique(array_diff($this->contactIds, CalculatedData::getExcludedContactIDs())) as $contactID) {
      $rows = $this->getContactHistory($contactID, $startDate, $endDate);
      $this->saveContactHistory($contactID, $startDate, $endDate, $rows);
      foreach ($rows as $row) {
        $result[] = $row;
      }
    }
  }

  /**
   * Replace the contact's history rows from the start date and before the end date.
   *
   * @param int $contactID
   * @param string|null $startDate
   * @param string $endDate
   * @param array $rows
   *
   * @throws \Throwable
   */
  private function saveContactHistory(int $contactID, ?string $startDate, string $endDate, array $rows): void {
    $transaction = new \CRM_Core_Transaction();
    try {
      $where = 'entity_id = %1 AND log_date < %2';
      $params = [
        1 => [$contactID, 'Integer'],
        2 => [$endDate, 'String'],
      ];
      if ($startDate) {
        $where .= ' AND log_date >= %3';
        $params[3] = [$startDate, 'String'];
      }
      \CRM_Core_DAO::executeQuery("DELETE FROM wmf_donor_history WHERE $where", $params);
      if ($rows) {
        \CRM_Utils_SQL_Insert::into('wmf_donor_history')->rows($rows)->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollback();
      throw $e;
    }
    $transaction->commit();
  }

  /**
   * Replay the contact's history rows from the start date to before the end date.
   *
   * @param int $contactID
   * @param string|null $startDate
   * @param string $endDate
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getContactHistory(int $contactID, ?string $startDate, string $endDate): array {
    // Start from the defaults (though all defaults rows aren't logged initially).
    $currentValues = [];
    foreach ($this->loggedFieldsByTable as $fields) {
      foreach ($fields as $field => $spec) {
        $currentValues[$field] = (int) $spec['default_value'];
      }
    }
    $previousValues = $currentValues;
    $rows = [];
    if ($startDate) {
      $previousRow = WMFDonorHistory::get(FALSE)
        ->addWhere('entity_id', '=', $contactID)
        ->addWhere('log_date', '<', $startDate)
        ->addOrderBy('log_date', 'DESC')
        ->addOrderBy('log_id', 'DESC')
        ->setLimit(1)
        ->execute()->first();
      if ($previousRow) {
        foreach (array_keys($currentValues) as $field) {
          $currentValues[$field] = $previousRow[$field];
        }
        $previousValues = $currentValues;
      }
    }
    foreach ($this->getCalculationTimes($contactID, $endDate) as ['time' => $asOfDate, 'tables' => $tables]) {
      if ($startDate && $asOfDate < $startDate) {
        continue;
      }
      foreach ($tables as $tableName) {
        $values = $this->getValuesAsOf($tableName, $contactID, $asOfDate);
        foreach ($this->loggedFieldsByTable[$tableName] as $field => $spec) {
          $currentValues[$field] = (int) ($values[$field] ?? $spec['default_value']);
        }
      }
      $changedFields = [];
      foreach ($this->loggedFieldsByTable as $fields) {
        foreach ($fields as $field => $spec) {
          if ($previousValues[$field] !== $currentValues[$field]) {
            $changedFields[] = $spec['log_changes'];
          }
        }
      }
      if ($changedFields) {
        $previousValues = $currentValues;
        $rows[] = ['entity_id' => $contactID] + $currentValues + [
          'changed_fields' => \CRM_Utils_Array::implodePadded($changedFields),
          'log_date' => $asOfDate,
        ];
      }
    }
    return $rows;
  }

  /**
   * Get the times to calculate for, with the source tables that may have changed.
   *
   * Events are contributions, recurring log rows and unpauses, recurring start,
   * cancel and end dates before the first log row of recurrings that started
   * before logging did, and each financial year rollover after the first of the
   * aforementioned events. Events within MERGE_SECONDS of each other are
   * calculated together.
   *
   * @param int $contactID
   * @param string $endDate
   *
   * @return array
   *   [['time' => SQL datetime, 'tables' => [tableName, ...]], ...], in ascending time order.
   * @throws \CRM_Core_Exception
   */
  private function getCalculationTimes(int $contactID, string $endDate): array {
    $params = [
      1 => [$contactID, 'Integer'],
      2 => [$endDate, 'String'],
      3 => [CalculatedData::RECUR_LOG_START, 'String'],
    ];
    $events = [];

    // Get the times contributions were received.
    $receiveDates = \CRM_Core_DAO::executeQuery("
      SELECT receive_date
      FROM civicrm_contribution
      WHERE contact_id = %1 AND contribution_status_id = 1 AND receive_date < %2
        AND (trxn_id NOT LIKE 'RFD %' OR trxn_id IS NULL)
    ", $params)->fetchAll();
    foreach ($receiveDates as $receiveDate) {
      $events[] = ['time' => $receiveDate['receive_date'], 'tables' => ['civicrm_contribution']];
    }

    // For recurrings that started before logging did, get their start, cancel
    // and end dates from before their first log row.
    $recurs = \CRM_Core_DAO::executeQuery("
      SELECT c.start_date, c.cancel_date, c.end_date,
        (SELECT MIN(l.log_date) FROM log_civicrm_contribution_recur l WHERE l.id = c.id) AS first_log_date
      FROM civicrm_contribution_recur c
      WHERE c.contact_id = %1 AND c.start_date < %3
    ", $params)->fetchAll();
    $this->hasPreLogRecurrings = (bool) $recurs;
    foreach ($recurs as $recur) {
      foreach ([$recur['start_date'], $recur['cancel_date'], $recur['end_date']] as $date) {
        if ($date && $date >= $recur['start_date'] && $date < $endDate && ($date < $recur['first_log_date'] || !$recur['first_log_date'])) {
          $events[] = ['time' => $date, 'tables' => ['civicrm_contribution_recur']];
        }
      }
    }

    // Get the log rows for each change to the contact's recurrings.
    $logRows = \CRM_Core_DAO::executeQuery("
      SELECT l.id, l.log_date, l.contribution_status_id, l.next_sched_contribution_date, l.frequency_unit, l.start_date
      FROM civicrm_contribution_recur c
      INNER JOIN log_civicrm_contribution_recur l ON l.id = c.id
      WHERE c.contact_id = %1 AND l.log_date < %2
      ORDER BY l.id, l.log_date
    ", $params)->fetchAll();

    $previousRow = NULL;
    $isAfterTie = FALSE;
    foreach ($logRows as $logRow) {
      $logRow['status'] = \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $logRow['contribution_status_id']);
      if ($logRow['status'] === 'Processing') {
        continue;
      }
      // For a paused recurring, add an event when it's no longer paused,
      // because nothing is logged for that.
      $logRow['unpause_date'] = $this->getUnpauseDate($logRow);
      if ($logRow['unpause_date'] && $logRow['unpause_date'] > $logRow['log_date'] && $logRow['unpause_date'] < $endDate) {
        $events[] = ['time' => $logRow['unpause_date'], 'tables' => ['civicrm_contribution_recur']];
      }
      // Skip if recurring hasn't changed, e.g. moving the next sched payment
      // date, only if we have a previous row from the same recurring id.
      if (
        !($previousRow) ||
        $previousRow['id'] !== $logRow['id'] ||
        $isAfterTie ||
        $this->getRecurringState($previousRow, $logRow['log_date']) !== $this->getRecurringState($logRow, $logRow['log_date'])
      ) {
        $events[] = ['time' => $logRow['log_date'], 'tables' => ['civicrm_contribution_recur']];
      }
      // We shouldn't skip if immediately after two rows that happened at the
      // same second as we don't know which will be counted
      $isAfterTie = $previousRow && $previousRow['id'] === $logRow['id'] && $previousRow['log_date'] === $logRow['log_date'];
      $previousRow = $logRow;
    }
    if ($events) {
      // A rollover only recalculates the tables the contact has had an event
      // for by then, otherwise it's all defaults.
      $firstEventTimes = [];
      foreach ($events as $event) {
        foreach ($event['tables'] as $tableName) {
          $firstEventTimes[$tableName] = min($firstEventTimes[$tableName] ?? $endDate, $event['time']);
        }
      }
      for ($year = (int) substr(min($firstEventTimes), 0, 4); "$year-07-01 00:00:00" < $endDate; $year++) {
        $rollover = "$year-07-01 00:00:00";
        $tables = [];
        foreach ($firstEventTimes as $tableName => $firstEventTime) {
          if ($firstEventTime <= $rollover) {
            $tables[] = $tableName;
          }
        }
        if ($tables) {
          $events[] = ['time' => $rollover, 'tables' => $tables];
        }
      }
    }
    array_multisort(array_column($events, 'time'), SORT_ASC, $events);

    // An event less than MERGE_SECONDS after the previous one is merged and
    // takes the later time, but not across a financial year end, so the values
    // just before a rollover keep their row.
    $calculationTimes = [];
    foreach ($events as $event) {
      $previous = array_key_last($calculationTimes);
      if ($previous !== NULL
        && strtotime($event['time']) - strtotime($calculationTimes[$previous]['time']) < self::MERGE_SECONDS
        && $this->getFinancialYear($event['time']) === $this->getFinancialYear($calculationTimes[$previous]['time'])) {
        $calculationTimes[$previous]['time'] = $event['time'];
        $calculationTimes[$previous]['tables'] = array_unique(array_merge($calculationTimes[$previous]['tables'], $event['tables']));
      }
      else {
        $calculationTimes[] = $event;
      }
    }
    return $calculationTimes;
  }

  /**
   * Get what a recurring log row means for the recurring statuses at the time.
   *
   * Returns the four things that matter: status (TRUE/active, FALSE/inactive),
   * frequency_unit, start_date, if paused.
   *
   * @param array $logRow
   *   With its status name and unpause_date.
   * @param string $date
   *
   * @return array
   */
  private function getRecurringState(array $logRow, string $date): array {
    $status = $logRow['status'];
    if (in_array($status, ['Pending', 'In Progress', 'Processing'], TRUE)) {
      $status = TRUE;
    }
    elseif (in_array($status, ['Cancelled', 'Completed'], TRUE)) {
      $status = FALSE;
    }
    // Paused is NULL without a next payment, which CalculatedData leaves out, rather than not paused.
    return [$status, $logRow['frequency_unit'], $logRow['start_date'], $logRow['unpause_date'] === NULL ? NULL : $logRow['unpause_date'] > $date];
  }

  /**
   * Get when an active recurring's log row stops being paused, as CalculatedData calculates it.
   *
   * Ugly date math: when the time plus one unit (MySQL clamps month ends)
   * reaches the day before the next payment.
   *
   * @param array $logRow
   *   With its status name.
   *
   * @return string|null
   *   NULL if it isn't active or has no next payment.
   */
  private function getUnpauseDate(array $logRow): ?string {
    if (!$logRow['next_sched_contribution_date'] || !in_array($logRow['status'], ['Pending', 'In Progress', 'Processing'], TRUE)) {
      return NULL;
    }
    $unit = $logRow['frequency_unit'] === 'year' ? 'year' : 'month';
    $dayBefore = strtotime("{$logRow['next_sched_contribution_date']} -1 day");
    $unpauseTimestamp = strtotime("-1 $unit", $dayBefore);
    if (date('j', $unpauseTimestamp) !== date('j', $dayBefore)) {
      // No such day in the earlier month, so the switch is at the start of the next.
      $unpauseTimestamp = strtotime(date('Y-m-01 00:00:00', $unpauseTimestamp));
    }
    return date('Y-m-d H:i:s', $unpauseTimestamp);
  }

  /**
   * Get the source table's values for the contact as of the date.
   *
   * @param string $tableName
   * @param int $contactID
   * @param string $asOfDate
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getValuesAsOf(string $tableName, int $contactID, string $asOfDate): array {
    $processor = CalculatedData::createForSourceTables($tableName)[$tableName];
    $processor->setTriggerContext(FALSE)
      ->setAsOfDate($asOfDate)
      ->setIncludePreLogRecurrings($this->hasPreLogRecurrings)
      ->setWhereClause("c.contact_id = $contactID");
    $processor->filterDonorFields(array_keys($this->loggedFieldsByTable[$tableName]));
    return \CRM_Core_DAO::executeQuery($processor->getSelectSQL())->fetchAll()[0] ?? [];
  }

  /**
   * Get the financial year an SQL datetime is in, by the year it starts.
   *
   * @param string $time
   *
   * @return int
   */
  private function getFinancialYear(string $time): int {
    return (int) substr($time, 0, 4) - ((int) substr($time, 5, 2) < 7 ? 1 : 0);
  }

}

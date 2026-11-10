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
   * Events for the same recurring this close together are calculated as one
   * potential segment/status change.
   *
   * 30 minutes captures most queue delay.
   */
  public const MERGE_SECONDS = 1800;

  /**
   * How long after an OTG a post-payment recurring convert is calculated together with.
   */
  public const CONVERT_MERGE_SECONDS = 3 * 86400;

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
   * Each source table's select SQL for a financial year, with placeholders for the as-of date and contact.
   *
   * Within a financial year only the as-of date differs.
   *
   * @var string[]
   */
  private array $selectSQL = [];

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // Normalised as it's compared as a string.
    $endDate = date('Y-m-d H:i:s', strtotime($this->endDate ?? '+1 second'));
    // Events before the start date should perhaps be merged with events up to the PPMC merge time before it.
    $startDate = $this->startDate ? date('Y-m-d H:i:s', strtotime($this->startDate) - self::CONVERT_MERGE_SECONDS) : NULL;
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
      // Delete by primary key, as deleting by entity_id also locks the gap its
      // rows would be in, which could deadlock with other inserts into that gap.
      $logIDs = \CRM_Core_DAO::executeQuery("SELECT log_id FROM wmf_donor_history WHERE $where", $params)->fetchMap('log_id', 'log_id');
      if ($logIDs) {
        \CRM_Core_DAO::executeQuery('DELETE FROM wmf_donor_history WHERE log_id IN (' . implode(', ', $logIDs) . ')');
      }
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
    foreach ($this->getCalculationTimes($contactID, $endDate) as ['time' => $asOfDate, 'logDate' => $logDate, 'tables' => $tables]) {
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
          'log_date' => $logDate ?? $asOfDate,
        ];
      }
    }
    return $rows;
  }

  /**
   * Get the times to calculate for, with the source tables that may have changed.
   *
   * @param int $contactID
   * @param string $endDate
   *
   * @return array
   *   [['time' => SQL datetime, 'logDate' => ?SQL datetime, 'tables' => [tableName, ...], 'recurID' => ?string, 'isConvert' => bool], ...], in ascending time order.
   *   A NULL logDate means logged at the time.
   * @throws \CRM_Core_Exception
   */
  private function getCalculationTimes(int $contactID, string $endDate): array {
    $events = $this->getEvents($contactID, $endDate);
    $events = array_merge($events, $this->getRollovers($events, $endDate));
    array_multisort(array_column($events, 'time'), SORT_ASC, $events);
    return $this->mergeEvents($events);
  }

  /**
   * Get the contact's events before the end date.
   *
   * Events are contributions, recurring log rows and unpauses, and for
   * recurrings that started before logging began, recurring start, cancel and
   * end dates.
   *
   * @param int $contactID
   * @param string $endDate
   *
   * @return array
   *   [['time' => SQL datetime, 'tables' => [tableName, ...], 'recurID' => ?string, 'isConvert' => bool], ...]
   * @throws \CRM_Core_Exception
   */
  private function getEvents(int $contactID, string $endDate): array {
    $params = [
      1 => [$contactID, 'Integer'],
      2 => [$endDate, 'String'],
      3 => [CalculatedData::RECUR_LOG_START, 'String'],
    ];
    $events = [];

    // Get the times contributions were received, join to post-payment convert recurring if there is one.
    $receiveDates = \CRM_Core_DAO::executeQuery("
      SELECT c.receive_date, IFNULL(c.contribution_recur_id, r.id) AS recur_id, r.id IS NOT NULL AS is_convert
      FROM civicrm_contribution c
      LEFT JOIN civicrm_contribution_recur r ON c.contribution_recur_id IS NULL AND r.invoice_id = c.invoice_id
      WHERE c.contact_id = %1 AND c.contribution_status_id = 1 AND c.receive_date < %2
        AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)
    ", $params)->fetchAll();
    foreach ($receiveDates as $receiveDate) {
      $events[] = ['time' => $receiveDate['receive_date'], 'tables' => ['civicrm_contribution'], 'recurID' => $receiveDate['recur_id'], 'isConvert' => (bool) $receiveDate['is_convert']];
    }

    // For recurrings that started before logging did, get their start, cancel
    // and end dates from before their first log row.
    $recurs = \CRM_Core_DAO::executeQuery("
      SELECT c.id, c.start_date, c.cancel_date, c.end_date,
        (SELECT MIN(l.log_date) FROM log_civicrm_contribution_recur l WHERE l.id = c.id) AS first_log_date
      FROM civicrm_contribution_recur c
      WHERE c.contact_id = %1 AND c.start_date < %3
    ", $params)->fetchAll();
    $this->hasPreLogRecurrings = (bool) $recurs;
    foreach ($recurs as $recur) {
      foreach ([$recur['start_date'], $recur['cancel_date'], $recur['end_date']] as $date) {
        if ($date && $date >= $recur['start_date'] && $date < $endDate && ($date < $recur['first_log_date'] || !$recur['first_log_date'])) {
          $events[] = ['time' => $date, 'tables' => ['civicrm_contribution_recur'], 'recurID' => $recur['id'], 'isConvert' => FALSE];
        }
      }
    }

    // Get the log rows for each change to the contact's recurrings.
    $logRows = \CRM_Core_DAO::executeQuery("
      SELECT l.id, l.log_date, l.contribution_status_id, l.next_sched_contribution_date, l.frequency_unit, l.start_date, l.end_date, l.cancel_date
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
      // Completed just meant in progress up to ~2021.
      if ($logRow['status'] === 'Completed' && !$logRow['end_date'] && !$logRow['cancel_date']) {
        $logRow['status'] = 'In Progress';
      }
      // For a paused recurring, add an event when it's no longer paused,
      // because nothing is logged for that.
      $logRow['unpause_date'] = $this->getUnpauseDate($logRow);
      if ($logRow['unpause_date'] && $logRow['unpause_date'] > $logRow['log_date'] && $logRow['unpause_date'] < $endDate) {
        $events[] = ['time' => $logRow['unpause_date'], 'tables' => ['civicrm_contribution_recur'], 'recurID' => $logRow['id'], 'isConvert' => FALSE];
      }
      // Skip if recurring hasn't changed, e.g. moving the next sched payment
      // date, only if we have a previous row from the same recurring id.
      if (
        !($previousRow) ||
        $previousRow['id'] !== $logRow['id'] ||
        $isAfterTie ||
        $this->getRecurringState($previousRow, $logRow['log_date']) !== $this->getRecurringState($logRow, $logRow['log_date'])
      ) {
        $events[] = ['time' => $logRow['log_date'], 'tables' => ['civicrm_contribution_recur'], 'recurID' => $logRow['id'], 'isConvert' => FALSE];
      }
      // We shouldn't skip if immediately after two rows that happened at the
      // same second as we don't know which will be counted
      $isAfterTie = $previousRow && $previousRow['id'] === $logRow['id'] && $previousRow['log_date'] === $logRow['log_date'];
      $previousRow = $logRow;
    }
    return $events;
  }

  /**
   * Get each financial year rollover after the first of the events.
   *
   * @param array $events
   * @param string $endDate
   *
   * @return array
   *   Events, as from getEvents().
   */
  private function getRollovers(array $events, string $endDate): array {
    if (!$events) {
      return [];
    }
    // A rollover only recalculates the tables the contact has had an event
    // for by then, otherwise it's all defaults.
    $firstEventTimes = [];
    foreach ($events as $event) {
      foreach ($event['tables'] as $tableName) {
        $firstEventTimes[$tableName] = min($firstEventTimes[$tableName] ?? $endDate, $event['time']);
      }
    }
    $rollovers = [];
    for ($year = (int) substr(min($firstEventTimes), 0, 4); "$year-07-01 00:00:00" < $endDate; $year++) {
      $rollover = "$year-07-01 00:00:00";
      $tables = [];
      foreach ($firstEventTimes as $tableName => $firstEventTime) {
        if ($firstEventTime <= $rollover) {
          $tables[] = $tableName;
        }
      }
      if ($tables) {
        $rollovers[] = ['time' => $rollover, 'tables' => $tables, 'recurID' => NULL, 'isConvert' => FALSE];
      }
    }
    return $rollovers;
  }

  /**
   * Merge events into the times to calculate for.
   *
   * Consecutive events for the same recurring within MERGE_SECONDS of each
   * other, other than two contributions, and events in the same second are
   * calculated together. So is a gift and the recurring post-payment converted
   * from it, within the longer CONVERT_MERGE_SECONDS. They're calculated and
   * logged as of the latest, except a donation post-payment converted to
   * recurring is logged at the original contribution time. We don't merge over
   * a fiscal year end, so we keep the end of year values.
   *
   * @param array $events
   *   As from getEvents(), in ascending time order.
   *
   * @return array
   *   As from getCalculationTimes().
   */
  private function mergeEvents(array $events): array {
    $calculationTimes = [];
    foreach ($events as $event) {
      $previous = array_key_last($calculationTimes);
      $mergeSeconds = ($previous !== NULL && $calculationTimes[$previous]['isConvert']) ? self::CONVERT_MERGE_SECONDS : self::MERGE_SECONDS;
      if ($previous !== NULL && (
        $event['time'] === $calculationTimes[$previous]['time']
        || ($event['recurID'] && $event['recurID'] === $calculationTimes[$previous]['recurID']
          && !(in_array('civicrm_contribution', $event['tables'], TRUE) && in_array('civicrm_contribution', $calculationTimes[$previous]['tables'], TRUE))
          && strtotime($event['time']) - strtotime($calculationTimes[$previous]['time']) < $mergeSeconds
          && $this->getFinancialYear($event['time']) === $this->getFinancialYear($calculationTimes[$previous]['time']))
      )) {
        // A converted gift is logged at the initial contribution time, even with later changes merged in.
        // Only the first merge for a PPMC gets the longer window and the gift's time.
        if ($calculationTimes[$previous]['isConvert']) {
          $calculationTimes[$previous]['logDate'] = $calculationTimes[$previous]['time'];
          $calculationTimes[$previous]['isConvert'] = FALSE;
        }
        $calculationTimes[$previous]['time'] = $event['time'];
        $calculationTimes[$previous]['tables'] = array_unique(array_merge($calculationTimes[$previous]['tables'], $event['tables']));
      }
      else {
        $calculationTimes[] = $event + ['logDate' => NULL];
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
    $financialYear = $this->getFinancialYear($asOfDate);
    $placeholderDate = "$financialYear-07-01 23:59:59";
    $key = $tableName . '_' . $financialYear . '_' . (int) $this->hasPreLogRecurrings;
    if (!isset($this->selectSQL[$key])) {
      $processor = CalculatedData::createForSourceTables($tableName)[$tableName];
      $processor->setTriggerContext(FALSE)
        ->setAsOfDate($placeholderDate)
        ->setIncludePreLogRecurrings($this->hasPreLogRecurrings)
        ->setWhereClause('c.contact_id = {contact_id}');
      $processor->filterDonorFields(array_keys($this->loggedFieldsByTable[$tableName]));
      $this->selectSQL[$key] = $processor->getSelectSQL();
    }
    $sql = str_replace([$placeholderDate, '{contact_id}'], [$asOfDate, $contactID], $this->selectSQL[$key]);
    return \CRM_Core_DAO::executeQuery($sql)->fetchAll()[0] ?? [];
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

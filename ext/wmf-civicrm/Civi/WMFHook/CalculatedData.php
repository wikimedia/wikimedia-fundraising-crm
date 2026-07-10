<?php
// Class to hold wmf functionality that alters permissions.

namespace Civi\WMFHook;

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Generic\Result;
use CRM_Core_PseudoConstant;

class CalculatedData extends TriggerHook {

  protected const WMF_MIN_ROLLUP_YEAR = 2019;

  protected const WMF_MAX_ROLLUP_YEAR = 2026;

  protected const WMF_MIN_CALENDER_YEAR = 2023;

  protected const WMF_MIN_INDEXED_YEAR = 2021;

  protected const WMF_MIN_FINANCIAL_YEAR_START = 2017;

  /**
   * Is this class is being called in trigger context.
   *
   * The normal context is 'trigger' which will generate sql triggers.
   * However, sometimes we want to do an sql_update to backfill
   * missing wmf_donor data - in which case we want to get
   * the same sql but to refer to the existing contact id rather
   * than the NEW or OLD contact ids - which are key-words that
   * are only meaningful in the context of triggers.
   *
   * @var bool
   */
  protected bool $triggerContext = TRUE;

  /**
   * SQL for the segment selects.
   *
   * @var string
   */
  private $oldSegmentSelectSQL;

  /**
   * SQL for the status selects.
   *
   * @var string
   */
  private $oldStatusSelectSQL;

  /**
   * SQL for the 2026 segment selects.
   *
   * @var string
   */
  private $segmentSelectSQL;

  /**
   * SQL for the status selects.
   *
   * Array of strings by type (overall, OTG, monthly, yearly)
   *
   * @var array
   */
  private $statusSelectSQL;

  /**
   * Cached field option_values, keyed by field name.
   *
   * @var array
   */
  private $fieldOptions = [];

  /**
   * Cached donor status options, keyed by field.
   *
   * @var array
   */
  private $donorStatusOptions = [];

  /**
   * Where clause to restrict contacts/ contributions to include.
   *
   * This clause is used when doing an update out of trigger context
   * to restrict the contacts being updated.
   *
   * @var string
   */
  protected $whereClause;

  /**
   * Fields that are based on year-based totals.
   *
   * Array of arrays by table name
   *
   * @var array
   */
  protected $calculatedFields;

  /**
   * Get the select clauses for year field for use when GROUP BY is not in use.
   *
   *  Array of arrays by table name
   *
   * @var array
   */
  protected $yearFieldSelects;

  /**
   * Get the select clauses for year field for use when GROUP BY is in use.
   *
   * Array of arrays by table name
   *
   * @var array
   */
  protected $selectsAggregate;

  /**
   * Get the select clauses for year field for use when GROUP BY is in use.
   *
   * @var array
   */
  protected $updateClauses;

  /**
   * Get the tables we add donor-recalculation triggers to, keyed by table
   * name, with the fields whose change should fire a recalculation.
   *
   * @return array
   */
  public static function getTriggerTables(): array {
    return [
      'civicrm_contribution' => [
        'significantFields' => ['contribution_status_id', 'total_amount', 'contact_id', 'receive_date', 'currency', 'financial_type_id'],
        'getSelectSQL' => 'getContributionSelectSQL',
      ],
      'civicrm_contribution_recur' => [
        'significantFields' => ['contribution_status_id', 'contact_id', 'frequency_unit', 'start_date', 'next_sched_contribution_date', 'amount'],
        'getSelectSQL' => 'getRecurringSelectSQL',
      ],
    ];
  }

  /**
   * Get a CalculatedData processor for each source table.
   *
   * wmf_donor is populated from more than one source table, each handled by its
   * own per-table instance.
   *
   * @param string|null $tableName
   *
   * @return self[]
   */
  public static function createForSourceTables(?string $tableName = NULL): array {
    $processors = [];
    foreach (array_keys(self::getTriggerTables()) as $table) {
      if (!$tableName || $tableName === $table) {
        $processor = new self();
        $processor->setTableName($table);
        $processors[$table] = $processor;
      }
    }
    return $processors;
  }

  /**
   * @param bool $triggerContext
   *
   * @return \Civi\WMFHook\CalculatedData
   */
  public function setTriggerContext(bool $triggerContext): self {
    $this->triggerContext = $triggerContext;
    return $this;
  }

  protected function getCalculatedFields(): array {
    if ($this->calculatedFields === NULL) {
      $this->getWMFDonorFields();
    }
    // If no table is set, for compatibility with existing code return a flattened array of all fields
    if ($this->getTableName() === NULL) {
      return array_merge(...array_values($this->calculatedFields));
    }
    return $this->calculatedFields[$this->getTableName()];
  }

  /**
   * Get the available field options.
   *
   * @param string $fieldName
   *
   * @return array []
   * @throws \CRM_Core_Exception
   */
  protected function getFieldOptions(string $fieldName): array {
    if (!isset($this->fieldOptions[$fieldName])) {
      $this->fieldOptions[$fieldName] = $this->getWMFDonorFields()[$fieldName]['option_values'] ?? [];
    }
    return $this->fieldOptions[$fieldName];
  }

  /**
   * Get the relevant label.
   *
   * @param string $fieldName
   * @param $value
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getFieldLabel(string $fieldName, $value): string {
    return $this->getFieldOptions($fieldName)[$value]['label'];
  }

  /**
   * Get the relevant label.
   *
   * @param string $fieldName
   * @param $value
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getFieldName(string $fieldName, $value): string {
    return $this->getFieldOptions($fieldName)[$value]['name'];
  }

  /**
   * Get the relevant description.
   *
   * @param string $fieldName
   * @param $value
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getFieldDescription(string $fieldName, $value): string {
    return $this->getFieldOptions($fieldName)[$value]['description'];
  }

  /**
   * Get select clauses for the year-based fields in non-Group By mode.
   *
   * @return array
   */
  protected function getTotalsFieldSelects(): array {
    $tableName = $this->getTableName();
    if (!isset($this->yearFieldSelects[$tableName])) {
      $this->yearFieldSelects[$tableName] = [];
      foreach ($this->getCalculatedFields() as $yearField) {
        if (($yearField['table_alias'] ?? 'totals') === 'totals') {
          $select = $yearField['select_clause'];
          if (!str_starts_with($select, "\n")) {
            // Fields starting with a newline supply their own blank line - don't indent it.
            $select = '        ' . $select;
          }
          $this->yearFieldSelects[$tableName][$yearField['name']] = $select;
        }
      }
    }
    return $this->yearFieldSelects[$tableName];
  }

  /**
   * Get select clauses for the year-based fields in non-Group By mode.
   *
   * @return array
   */
  protected function getUpdateClauses(): array {
    if ($this->updateClauses === NULL) {
      $this->updateClauses = [];
      foreach ($this->getCalculatedFields() as $yearField) {
        $this->updateClauses[$yearField['name']] = $yearField['name'] . ' = VALUES(' . $yearField['name'] . ')';
      }
    }
    return $this->updateClauses;
  }

  /**
   * Get select clauses for the year-based fields in Group By mode.
   *
   * To honour FULL_GROUP_BY mysql mode we need an aggregate command for each
   * field - even though we know we just want `the value from the subquery`
   * MAX is a safe wrapper for that
   * https://www.percona.com/blog/2019/05/13/solve-query-failures-regarding-only_full_group_by-sql-mode/
   *
   * @return array
   */
  protected function getSelectsAggregate(): array {
    $tableName = $this->getTableName();
    if (!isset($this->selectsAggregate[$tableName])) {
      $this->selectsAggregate[$tableName] = [];
      foreach ($this->getCalculatedFields() as $calculatedField) {
        if (!empty($calculatedField['aggregate_select_clause'])) {
          $this->selectsAggregate[$tableName][$calculatedField['name']] = $calculatedField['aggregate_select_clause'];
        }
        else {
          // When full group by is applied (which it is not currently on our prod but at some point...)
          // it is necessary to aggregate all fields in some way - MAX is a stand in for when there is
          // only one value of interest.
          $operator = $calculatedField['group_by_operator'] ?? 'MAX';
          $this->selectsAggregate[$tableName][$calculatedField['name']] = $operator . '(' . $calculatedField['name'] . ") as " . $calculatedField['name'];
        }
      }
    }
    return $this->selectsAggregate[$tableName];
  }

  public static function getCalculatedCustomFieldGroupID(): int {
    return CustomGroup::get(FALSE)
      ->setSelect(['id'])
      ->addWhere('name', '=', 'wmf_donor')
      ->execute()
      ->first()['id'];
  }

  /**
   * Get (basic) data about the wmf donor fields.
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   *
   */
  public static function getCalculatedCustomFields(): Result {
    return CustomField::get(FALSE)
      ->setSelect(['id', 'name', 'label'])
      ->addWhere('custom_group_id:name', '=', 'wmf_donor')
      ->execute();
  }

  /**
   * @return mixed
   */
  protected function getWhereClause() {
    return $this->whereClause;
  }

  /**
   * @param mixed $whereClause
   *
   * @return CalculatedData
   */
  public function setWhereClause($whereClause): CalculatedData {
    $this->whereClause = $whereClause;
    return $this;
  }

  /**
   * @return string
   */
  protected function isTriggerContext(): string {
    return $this->triggerContext;
  }

  /**
   * Add triggers for our calculated custom fields.
   *
   * Whenever a contribution or recurring contribution is updated the fields are
   * re-calculated provided the change is an update, a delete or an update which
   * alters a relevant field per getTriggerTables().)
   *
   * All fields in the dataset are recalculated (the performance gain on a
   * 'normal' contact of being more selective was too little to show in testing.
   * On our anonymous contact it was perhaps 100 ms, but we don't have many
   * contact with thousands of donations.)
   *
   * The wmf_contribution_extra record is saved after the contribution is
   * inserted.
   *
   * so we need to potentially update the fields from that record at that points,
   * with a separate trigger.
   *
   * @throws \CRM_Core_Exception
   */
  public function triggerInfo(): array {
    if (!$this->isDBReadyForTriggers()) {
      // We are expecting this to run through fully later so it is a minor
      // optimisation to do less now.
      return [];
    }
    $info = [];
    $tableName = $this->getTableName();
    $updateConditions = [];
    foreach (self::getTriggerTables()[$tableName]['significantFields'] as $significantField) {
      $updateConditions[] = "(NEW.{$significantField} != OLD.{$significantField})";
    }

    $requiredClauses = [1];

    $matchingGiftDonors = civicrm_api3('Contact', 'get', ['nick_name' => ['IN' => ['Microsoft', 'Google', 'Apple', 'Citi']]])['values'];
    $excludedContacts = array_keys($matchingGiftDonors);
    $anonymousContact = civicrm_api3('Contact', 'get', [
      'first_name' => 'Anonymous',
      'last_name' => 'Anonymous',
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);
    if ($anonymousContact['count']) {
      $excludedContacts[] = $anonymousContact['id'];
    }
    if (!empty($excludedContacts)) {
      // On live there will always be an anonymous contact. Check is just for dev instances.
      $requiredClauses[] = '(NEW.contact_id NOT IN (' . implode(',', $excludedContacts) . '))';
    }

    $updateClauses = $requiredClauses;
    if ($tableName === 'civicrm_contribution_recur') {
      $processingID = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Processing');
      // For updates, don't recalculate while processing each recurring, only after processing finishes.
      $updateClauses[] = "(NEW.contribution_status_id <> $processingID)";
    }

    $sql = $this->getUpdateWMFDonorSql();
    $insertSQL = ' IF ' . implode(' AND ', $requiredClauses) . ' THEN' . $sql . "\nEND IF;\n";
    $updateSQL = ' IF ' . implode(' AND ', $updateClauses) . ' AND (' . implode(' OR ', $updateConditions) . ' ) THEN' . $this->getUpdateWMFDonorSql(TRUE) . "\nEND IF;\n";
    $requiredClausesForOldClause = str_replace('NEW.', 'OLD.', implode(' AND ', $requiredClauses));
    $oldSql = str_replace('NEW.', 'OLD.', $sql);
    $updateOldSQL = ' IF ' . $requiredClausesForOldClause
      . ' AND (NEW.contact_id <> OLD.contact_id) THEN'
      . $oldSql . "\nEND IF;\n";

    $deleteSql = ' IF ' . $requiredClausesForOldClause . ' THEN' . $oldSql . "\nEND IF;\n";

    // We want to fire this trigger on insert, update and delete.
    $info[] = [
      'table' => $tableName,
      'when' => 'AFTER',
      'event' => 'INSERT',
      'sql' => $insertSQL,
    ];
    $info[] = [
      'table' => $tableName,
      'when' => 'AFTER',
      'event' => 'UPDATE',
      'sql' => $updateSQL,
    ];
    $info[] = [
      'table' => $tableName,
      'when' => 'AFTER',
      'event' => 'UPDATE',
      'sql' => $updateOldSQL,
    ];
    // For delete, we reference OLD.field instead of NEW.field
    $info[] = [
      'table' => $tableName,
      'when' => 'AFTER',
      'event' => 'DELETE',
      'sql' => $deleteSql,
    ];
    return $info;
  }

  /**
   * Is our database ready for triggers to be created.
   *
   * If we are still building our environment and the donor custom fields
   * and endowment financial type are not yet present we should skip
   * adding our triggers until later.
   *
   * If this were to be the case on production I think we would have
   * bigger issues than triggers so this should be a dev-only concern.
   *
   * @return false
   *
   * @throws \CRM_Core_Exception
   */
  protected function isDBReadyForTriggers(): bool {
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    if (!$endowmentFinancialType) {
      return FALSE;
    }
    $wmfDonorQuery = CustomGroup::get(FALSE)->addWhere('name', '=', 'wmf_donor')->execute();
    return (bool) count($wmfDonorQuery);
  }

  /**
   * @param array $fieldsToShow
   */
  public function filterDonorFields(array $fieldsToShow): void {
    $tableName = $this->getTableName();
    $allFields = $this->getCalculatedFields();
    // Reduce this table's calculated fields to the intersection.
    $this->calculatedFields[$tableName] = array_intersect_key($allFields, array_fill_keys($fieldsToShow, 1));

    // Put back join fields if needed for other fields, or to ensure there is at least one field present.
    if ($tableName === 'civicrm_contribution') {
      if (empty($this->calculatedFields[$tableName]) || $this->isIncludeTable('latest')) {
        $this->calculatedFields[$tableName]['all_funds_last_donation_date'] = $allFields['all_funds_last_donation_date'];
      }
      if ($this->isIncludeTable('earliest')) {
        $this->calculatedFields[$tableName]['all_funds_first_donation_date'] = $allFields['all_funds_first_donation_date'];
      }
      if ($this->isIncludeTable('largest')) {
        $this->calculatedFields[$tableName]['largest_donation'] = $allFields['largest_donation'];
      }
    }
  }

  /**
   * Get the tables / subqueries that are required.
   *
   * @return array
   */
  protected function getRequiredTables(): array {
    $tables = [];
    foreach ($this->getCalculatedFields() as $field) {
      $table = $field['table_alias'] ?? 'wmf_donor';
      $tables[$table] = $table;
    }
    return $tables;
  }

  /**
   * Is the table/ subquery to be joined in.
   *
   * @param string $tableAlias
   *
   * @return bool
   */
  protected function isIncludeTable($tableAlias): bool {
    return isset($this->getRequiredTables()[$tableAlias]);
  }

  /**
   * Get fields for wmf_donor custom group.
   *
   * This is the group with the custom fields for calculated donor data.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getWMFDonorFields(): array {
    $endowmentFinancialType = $this->getEndowmentFinancialType();
    $contributionFields = [
      'donor_segment_overall' => [
        'name' => 'donor_segment_overall',
        'column_name' => 'donor_segment_overall',
        'label' => ts('Donor Segment'),
        'data_type' => 'Int',
        'default_value' => 990,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'log_changes' => 1,
        'table_alias' => 'consecutive_years',
        'aggregate_select_clause' => $this->getSegmentSelect(),
        'option_values' => $this->getDonorSegmentOptions(),
      ],
      'years_consecutive' => [
        'name' => 'years_consecutive',
        'column_name' => 'years_consecutive',
        'label' => ts('Consecutive Giving Years'),
        'data_type' => 'Int',
        'default_value' => 0,
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'table_alias' => 'consecutive_years',
        'aggregate_select_clause' => 'COALESCE(MAX(consecutive_years.years_consecutive), 0) as years_consecutive',
      ],
      'donor_status_overall' => [
        'name' => 'donor_status_overall',
        'column_name' => 'donor_status_overall',
        'label' => ts('Donor Status: Overall'),
        'data_type' => 'Int',
        'default_value' => 99,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'log_changes' => 2,
        'select_clause' => $this->getOverallOTGDonorStatusSelect('donor_status_overall'),
        'option_values' => $this->getSpecifiedDonorStatusOptions('donor_status_overall'),
      ],
      'donor_status_otg' => [
        'name' => 'donor_status_otg',
        'column_name' => 'donor_status_otg',
        'label' => ts('Donor Status: OTG'),
        'data_type' => 'Int',
        'default_value' => 99,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'log_changes' => 3,
        'select_clause' => $this->getOverallOTGDonorStatusSelect('donor_status_otg'),
        'option_values' => $this->getSpecifiedDonorStatusOptions('donor_status_otg'),
      ],
      'donor_segment_id' => [
        'name' => 'donor_segment_id',
        'column_name' => 'donor_segment_id',
        'label' => ts('Old Donor Segment'),
        'data_type' => 'Int',
        'default_value' => 1000,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'select_clause' => $this->getOldSegmentSelect(),
        'option_values' => $this->getOldDonorSegmentOptions(),
      ],
      'donor_status_id' => [
        'name' => 'donor_status_id',
        'column_name' => 'donor_status_id',
        'label' => ts('Old Donor Status'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'select_clause' => $this->getOldSegmentStatusSelect(),
        'option_values' => $this->getOldDonorStatusOptions(),
      ],
      'last_donation_currency' => [
        'name' => 'last_donation_currency',
        'column_name' => 'last_donation_currency',
        'label' => ts('Last Donation Currency'),
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'table_alias' => 'latest',
        'select_clause' => 'COALESCE(x.original_currency, latest.currency) as last_donation_currency',
        'aggregate_select_clause' => 'MAX(COALESCE(x.original_currency,
 latest.currency)) as last_donation_currency',
      ],
      'last_donation_amount' => [
        'name' => 'last_donation_amount',
        'column_name' => 'last_donation_amount',
        'label' => ts('Last Donation Amount (Original Currency)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'table_alias' => 'latest',
        'select_clause' => 'COALESCE(x.original_amount, latest.total_amount, 0) as last_donation_amount',
        'aggregate_select_clause' => 'MAX(COALESCE(x.original_amount, latest.total_amount, 0)) as last_donation_amount',
      ],
      'last_donation_usd' => [
        'name' => 'last_donation_usd',
        'column_name' => 'last_donation_usd',
        'label' => ts('Last Donation Amount (USD)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'table_alias' => 'latest',
        'select_clause' => 'COALESCE(latest.total_amount, 0) as last_donation_usd',
        'aggregate_select_clause' => 'MAX(COALESCE(latest.total_amount, 0)) as last_donation_usd',
      ],
      'first_donation_usd' => [
        'name' => 'first_donation_usd',
        'column_name' => 'first_donation_usd',
        'label' => ts('First Donation Amount (USD)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'table_alias' => 'earliest',
        'select_clause' => 'COALESCE(earliest.total_amount, 0) as first_donation_usd,',
        'aggregate_select_clause' => 'MAX(COALESCE(earliest.total_amount, 0)) as first_donation_usd',
      ],
      'first_donation_was_recur' => [
        'name' => 'first_donation_was_recur',
        'column_name' => 'first_donation_was_recur',
        'label' => ts('First donation was recurring?'),
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_active' => 1,
        'is_searchable' => 0,
        'is_view' => 1,
        'table_alias' => 'earliest',
        'aggregate_select_clause' => 'MAX(IF(earliest.id IS NULL, NULL, earliest.contribution_recur_id IS NOT NULL OR ppmc_recur.id IS NOT NULL)) as first_donation_was_recur',
      ],
      'date_of_largest_donation' => [
        'name' => 'date_of_largest_donation',
        'column_name' => 'date_of_largest_donation',
        'label' => ts('Date of Largest Donation'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'table_alias' => 'largest',
        'select_clause' => 'MAX(largest.receive_date) as date_of_largest_donation',
        'aggregate_select_clause' => 'MAX(largest.receive_date) as date_of_largest_donation',
      ],
      'largest_donation' => [
        'name' => 'largest_donation',
        'column_name' => 'largest_donation',
        'label' => ts('Largest Donation'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => "MAX(IF(c.financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation",
      ],
      'endowment_largest_donation' => [
        'name' => 'endowment_largest_donation',
        'column_name' => 'endowment_largest_donation',
        'label' => ts('Endowment Largest Donation'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => "MAX(IF(c.financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_largest_donation",
      ],
      'all_funds_largest_donation' => [
        'name' => 'all_funds_largest_donation',
        'column_name' => 'all_funds_largest_donation',
        'label' => ts('All Funds Largest Donation'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => "MAX(COALESCE(total_amount, 0)) AS all_funds_largest_donation",
      ],
      'lifetime_including_endowment' => [
        'name' => 'lifetime_including_endowment',
        'column_name' => 'lifetime_including_endowment',
        'label' => ts('Lifetime Donations (USD) (Incl Endowments)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'select_clause' => "SUM(COALESCE(total_amount, 0)) AS lifetime_including_endowment",
      ],
      'all_funds_last_donation_date' => [
        'name' => 'all_funds_last_donation_date',
        'column_name' => 'all_funds_last_donation_date',
        'label' => ts('All Funds Last donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'select_clause' => "MAX(IF(total_amount > 0, receive_date, NULL)) AS all_funds_last_donation_date",
      ],
      'last_otg_donation_date' => [
        'name' => 'last_otg_donation_date',
        'column_name' => 'last_otg_donation_date',
        'label' => ts('Last OTG Donation Date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'select_clause' => "MAX(IF(total_amount > 0 AND contribution_recur_id IS NULL, receive_date, NULL)) AS last_otg_donation_date",
      ],
      'first_donation_date' => [
        'name' => 'first_donation_date',
        'column_name' => 'first_donation_date',
        'label' => ts('First donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'group_by_operator' => 'MIN',
        'select_clause' => "MIN(IF(c.financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS first_donation_date",
      ],
      'endowment_first_donation_date' => [
        'name' => 'endowment_first_donation_date',
        'column_name' => 'endowment_first_donation_date',
        'label' => ts('Endowment First donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'group_by_operator' => 'MIN',
        'select_clause' => "MIN(IF(c.financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_first_donation_date",
      ],
      'all_funds_first_donation_date' => [
        'name' => 'all_funds_first_donation_date',
        'column_name' => 'all_funds_first_donation_date',
        'label' => ts('All Funds First donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'group_by_operator' => 'MIN',
        'select_clause' => 'MIN(IF(total_amount > 0, receive_date, NULL)) AS all_funds_first_donation_date',
      ],
      'number_donations' => [
        'name' => 'number_donations',
        'column_name' => 'number_donations',
        'label' => ts('Number of Donations'),
        'data_type' => 'Int',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => "COUNT(IF(c.financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations",
      ],
      'endowment_number_donations' => [
        'name' => 'endowment_number_donations',
        'column_name' => 'endowment_number_donations',
        'label' => ts('Endowment Number of Donations'),
        'data_type' => 'Int',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => "COUNT(IF(c.financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_number_donations",
      ],
      'all_funds_number_donations' => [
        'name' => 'all_funds_number_donations',
        'column_name' => 'all_funds_number_donations',
        'label' => ts('All Funds Number of Donations'),
        'data_type' => 'Int',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'default_value' => 0,
        'select_clause' => 'COUNT(IF(total_amount > 0, receive_date, NULL)) AS all_funds_number_donations',
      ],
    ];

    for ($year = self::WMF_MIN_ROLLUP_YEAR; $year <= self::WMF_MAX_ROLLUP_YEAR; $year++) {
      $nextYear = $year + 1;
      // This weight setting seems to be ignored - but perhaps doesn't matter.
      $weight = $year - 2000;
      // Add financial year fields (5 years worth).
      $contributionFields["total_{$year}_{$nextYear}"] = [
        'name' => "total_{$year}_{$nextYear}",
        'column_name' => "total_{$year}_{$nextYear}",
        'label' => ts("FY {$year}-{$nextYear} total"),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'default_value' => 0,
        'is_active' => 1,
        'is_required' => 0,
        'is_searchable' => ($year >= self::WMF_MIN_INDEXED_YEAR),
        'is_view' => 1,
        'weight' => $weight,
        'is_search_range' => 1,
        'select_clause' => "SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}",
      ];
      // Add calendar year fields.
      if ($year >= self::WMF_MIN_CALENDER_YEAR) {
        $contributionFields["total_{$year}"] = [
          'name' => "total_{$year}",
          'column_name' => "total_{$year}",
          'label' => ts("CY {$year} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => ($year >= self::WMF_MIN_INDEXED_YEAR),
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as total_{$year}",
        ];
      }
      // Financial year totals for endowment (5 years)
      $contributionFields["endowment_total_{$year}_{$nextYear}"] = array_merge(
        $contributionFields["total_{$year}_{$nextYear}"], [
          'name' => "endowment_total_{$year}_{$nextYear}",
          'column_name' => "endowment_total_{$year}_{$nextYear}",
          'label' => 'Endowment ' . ts("FY {$year}-{$nextYear} total"),
          'select_clause' => "SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}_{$nextYear}",
        ]
      );
      // Endowment field total
      if ($year >= self::WMF_MIN_CALENDER_YEAR) {
        $contributionFields["endowment_total_{$year}"] = array_merge(
          $contributionFields["total_{$year}"], [
            'name' => "endowment_total_{$year}",
            'column_name' => "endowment_total_{$year}",
            'label' => 'Endowment ' . ts("CY {$year} total"),
            'select_clause' => "SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}",
          ]
        );
      }
      // Financial year totals for all funds (5 years)
      $contributionFields["all_funds_total_{$year}_{$nextYear}"] = array_merge(
        $contributionFields["total_{$year}_{$nextYear}"], [
          'name' => "all_funds_total_{$year}_{$nextYear}",
          'column_name' => "all_funds_total_{$year}_{$nextYear}",
          'label' => 'All Funds ' . ts("FY {$year}-{$nextYear} total"),
          'select_clause' => "SUM(COALESCE(IF(receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as all_funds_total_{$year}_{$nextYear}",
        ]
      );
      if ($nextYear >= self::WMF_MIN_CALENDER_YEAR) {
        // Change fields, year ending in this year onwards, co-incident with our calendar years.
        $contributionFields["all_funds_change_{$year}_{$nextYear}"] = [
          'name' => "all_funds_change_{$year}_{$nextYear}",
          'column_name' => "all_funds_change_{$year}_{$nextYear}",
          'label' => ts("All Funds Change {$year}-{$nextYear} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => ($year >= self::WMF_MIN_INDEXED_YEAR),
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "SUM(COALESCE(IF(receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
            - SUM(COALESCE(IF(receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
             as all_funds_change_{$year}_{$nextYear}",
        ];

        $contributionFields["endowment_change_{$year}_{$nextYear}"] = [
          'name' => "endowment_change_{$year}_{$nextYear}",
          'column_name' => "endowment_change_{$year}_{$nextYear}",
          'label' => ts("Endowment Change {$year}-{$nextYear} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => 0,
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "
             SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
            - SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
             as endowment_change_{$year}_{$nextYear}",
        ];
        $contributionFields["change_{$year}_{$nextYear}"] = [
          'name' => "change_{$year}_{$nextYear}",
          'column_name' => "change_{$year}_{$nextYear}",
          'label' => ts("Change {$year}-{$nextYear} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => 0,
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "
            SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
          as change_{$year}_{$nextYear}",
        ];
      }
    }
    $contributionRecurFields = [
      'donor_status_recur_overall' => [
        'name' => 'donor_status_recur_overall',
        'column_name' => 'donor_status_recur_overall',
        'label' => ts('Donor Status: Overall Recurring'),
        'data_type' => 'Int',
        'default_value' => 95,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'select_clause' => $this->getRecurringDonorStatusSelect('overall'),
        'option_values' => $this->getSpecifiedDonorStatusOptions('donor_status_recur_overall'),
      ],
      'donor_status_recur_month' => [
        'name' => 'donor_status_recur_month',
        'column_name' => 'donor_status_recur_month',
        'label' => ts('Donor Status: Monthly Recurring'),
        'data_type' => 'Int',
        'default_value' => 95,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'log_changes' => 4,
        'select_clause' => $this->getRecurringDonorStatusSelect('month'),
        'option_values' => $this->getSpecifiedDonorStatusOptions('donor_status_recur_month'),
      ],
      'donor_status_recur_year' => [
        'name' => 'donor_status_recur_year',
        'column_name' => 'donor_status_recur_year',
        'label' => ts('Donor Status: Annual Recurring'),
        'data_type' => 'Int',
        'default_value' => 95,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'log_changes' => 5,
        'select_clause' => $this->getRecurringDonorStatusSelect('year'),
        'option_values' => $this->getSpecifiedDonorStatusOptions('donor_status_recur_year'),
      ],
    ];

    $this->calculatedFields = [
      'civicrm_contribution' => $contributionFields,
      'civicrm_contribution_recur' => $contributionRecurFields,
    ];

    // Update-only fields compare NEW & OLD values, so they aren't in $this->calculatedFields
    // as they aren't used for Get/Update, but they should be in the field list.
    $allFields = $this->calculatedFields;
    foreach ($this->getUpdateOnlyFieldsByTable() as $table => $fields) {
      $allFields[$table] = array_merge($allFields[$table] ?? [], $fields);
    }

    return array_merge(...array_values($allFields));
  }

  /**
   * Get the update-only fields, keyed by source table.
   *
   * These fields are only used by the UPDATE trigger, see getUpdateWMFDonorSql().
   * These fields aren't updated by WMFDonor::update, past changes require manual backfill.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getUpdateOnlyFieldsByTable(): array {
    return [
      'civicrm_contribution_recur' => [
        'last_recurring_amount_change' => [
          'name' => 'last_recurring_amount_change',
          'column_name' => 'last_recurring_amount_change',
          'label' => ts('Last Recurring Amount Change (Native Currency)'),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'is_active' => 1,
          'is_view' => 1,
          'is_searchable' => 1,
          'update_select_clause' => 'IF(NEW.amount <> OLD.amount AND NEW.frequency_unit = OLD.frequency_unit, NEW.amount - OLD.amount, NULL)',
        ],
        'last_recurring_amount_change_date' => [
          'name' => 'last_recurring_amount_change_date',
          'column_name' => 'last_recurring_amount_change_date',
          'label' => ts('Last Recurring Amount Change Date'),
          'data_type' => 'Date',
          'html_type' => 'Select Date',
          'is_active' => 1,
          'is_view' => 1,
          'is_searchable' => 1,
          'update_select_clause' => 'IF(NEW.amount <> OLD.amount AND NEW.frequency_unit = OLD.frequency_unit, CURDATE(), NULL)',
        ],
      ],
    ];
  }

  /**
   * Get the update-only fields for the current source table.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getUpdateOnlyFields(): array {
    return $this->getUpdateOnlyFieldsByTable()[$this->getTableName()] ?? [];
  }

  /**
   * Get the outer select columns, optionally including update-only fields.
   *
   * @param bool $includeUpdateOnly
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getOuterSelects(bool $includeUpdateOnly): array {
    $selects = $this->getSelectsAggregate();
    if ($includeUpdateOnly) {
      foreach ($this->getUpdateOnlyFields() as $field) {
        $selects[$field['name']] = $field['update_select_clause'] . ' as ' . $field['name'];
      }
    }
    return $selects;
  }

  /**
   * Get the wmf_donor fields flagged for tracking in wmf_donor_history.
   *
   * Note that the log_changes int value is set for each field, but is intended
   * to reflect the column order in the table itself for Analytics' convenience.
   *
   * @return array
   *   Field specs keyed by name, filtered to those marked 'log_changes'.
   *
   * @throws \CRM_Core_Exception
   */
  public function getLoggedFields(): array {
    // Only int fields with a select (option list) are supported in the history table.
    return array_filter(
      $this->getWMFDonorFields(),
      fn($field) => !empty($field['log_changes']) && $field['data_type'] === 'Int' && $field['html_type'] === 'Select'
    );
  }

  /**
   * Pseudoconstant callback for wmf_donor_history fields.
   *
   * Returns the same in-code option values as the matching wmf_donor field, avoiding a
   * duplicate option group or doing a DB lookup here.
   *
   * @param string $fieldName
   *
   * @return array
   *   Options keyed by value.
   *
   * @throws \CRM_Core_Exception
   */
  public static function getHistoryFieldOptions(string $fieldName): array {
    $options = [];
    $field = (new self())->getWMFDonorFields()[$fieldName] ?? [];
    foreach ($field['option_values'] ?? [] as $option) {
      $options[$option['value']] = $option['label'];
    }
    return $options;
  }

  /**
   * Get the sql to update donor records.
   *
   * The sql winds up being an INSERT with on duplicate key update ie.
   *
   * INSERT INTO wmf_donor(entity_id, last_donation_currency...
   * NEW.contact_id as entity_id, MAX(COALESCE(x.original_currency, latest.currency)) as last_donation_currency...
   * ON DUPLICATE KEY UPDATE
   * last_donation_currency = VALUES(last_donation_currency), ...
   *
   * Update-only fields are folded into the same statement on UPDATE.
   * Their clause yields NULL when nothing relevant changed;
   * COALESCE then preserves the stored value rather than overwriting.
   *
   * @param bool $includeUpdateOnly
   *
   * @return string
   */
  protected function getUpdateWMFDonorSql(bool $includeUpdateOnly = FALSE): string {
    $columns = array_keys($this->getCalculatedFields());
    $updateClauses = $this->getUpdateClauses();
    if ($includeUpdateOnly) {
      foreach (array_keys($this->getUpdateOnlyFields()) as $column) {
        $columns[] = $column;
        $updateClauses[$column] = "$column = COALESCE(VALUES($column), $column)";
      }
    }
    return "
      INSERT INTO wmf_donor (
        entity_id,\n        " . implode(",\n        ", $columns) . "
      )
      " . $this->getSelectSQL($includeUpdateOnly) . "
     ON DUPLICATE KEY UPDATE
     " . implode(",\n     ", $updateClauses) . ";";

  }

  /**
   * Get the string to select the wmf donor data.
   *
   * @param bool $includeUpdateOnly
   *
   * @return string
   */
  public function getSelectSQL(bool $includeUpdateOnly = FALSE): string {
    // After filterDonorFields() this is only the requested fields for this table, so empty means no SQL needed.
    if (empty($this->getCalculatedFields())) {
      return '';
    }
    $method = self::getTriggerTables()[$this->getTableName()]['getSelectSQL'];
    return $this->$method($includeUpdateOnly);
  }

  /**
   * Get the string to select the wmf donor data for the recurring contribution table.
   *
   * @return string
   */
  protected function getRecurringSelectSQL(bool $includeUpdateOnly = FALSE): string {
    $innerColumns = $this->getTotalsFieldSelects();
    if ($this->isTriggerContext()) {
      $from = "FROM civicrm_contribution_recur c
        WHERE c.contact_id = NEW.contact_id";
      $entityID = 'NEW.contact_id';
    }
    else {
      // Add c.contact_id manually outside trigger context as we don't have NEW.contact_id to group by
      array_unshift($innerColumns, '        c.contact_id');
      $from = "FROM civicrm_contribution_recur c
        WHERE " . $this->getWhereClause() . "
        GROUP BY c.contact_id";
      $entityID = 'totals.contact_id';
    }
    return "SELECT $entityID as entity_id,
      " . implode(",\n      ", $this->getOuterSelects($includeUpdateOnly)) . "
      FROM (
        SELECT\n" . implode(",\n", $innerColumns) . "
        $from
      ) as totals
      GROUP BY $entityID";
  }

  /**
   * Get the string to select the wmf donor data fpr the contribution table.
   *
   * @return string
   */
  protected function getContributionSelectSQL(bool $includeUpdateOnly = FALSE): string {
    $innerColumns = $this->getTotalsFieldSelects();
    if ($this->isTriggerContext()) {
      $where = 'c.contact_id = NEW.contact_id';
      $groupBy = '';
      $entityID = 'NEW.contact_id';
    }
    else {
      // Add c.contact_id manually outside trigger context as we don't have NEW.contact_id to group by
      array_unshift($innerColumns, '        c.contact_id');
      $where = $this->getWhereClause();
      $groupBy = '  GROUP BY c.contact_id';
      $entityID = 'totals.contact_id';
    }
    return "SELECT $entityID as entity_id,
      " . implode(",\n      ", $this->getOuterSelects($includeUpdateOnly)) . "
      FROM (
        SELECT\n" . implode(",\n", $innerColumns) . "
  FROM civicrm_contribution c
  USE INDEX(FK_civicrm_contribution_contact_id)
  -- TODO: remove this join when removing donor_segment_id and donor_status_id
  LEFT JOIN civicrm_contribution_recur annual_recur
    ON annual_recur.id = c.contribution_recur_id
    AND annual_recur.frequency_unit = 'year'
  WHERE $where
    AND c.contribution_status_id = 1
    AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)
$groupBy
  ) as totals" .
      (!$this->isIncludeTable('latest') ? '' : "
  LEFT JOIN civicrm_contribution latest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON latest.contact_id = $entityID
    AND latest.receive_date = totals.all_funds_last_donation_date
    AND latest.contribution_status_id = 1
    AND latest.total_amount > 0
    AND (latest.trxn_id NOT LIKE 'RFD %' OR latest.trxn_id IS NULL)
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = latest.id") .
      (!$this->isIncludeTable('earliest') ? '' : "
  LEFT JOIN civicrm_contribution earliest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON earliest.contact_id = $entityID
    AND earliest.receive_date = totals.all_funds_first_donation_date
    AND earliest.contribution_status_id = 1
    AND earliest.total_amount > 0
    AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)
    LEFT JOIN civicrm_contribution_recur ppmc_recur
      ON ppmc_recur.invoice_id = earliest.invoice_id
      AND ppmc_recur.contact_id = $entityID") .
      (!$this->isIncludeTable('largest') ? '' : "
  LEFT JOIN civicrm_contribution largest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON largest.contact_id = $entityID
    AND largest.total_amount = totals.largest_donation
    AND largest.contribution_status_id = 1
    AND largest.total_amount > 0
    AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)") .
      (!$this->isIncludeTable('consecutive_years') ? '' : $this->getSegmentRollupJoin($where, $entityID)) . "
  GROUP BY $entityID";
  }

  /**
   * Get the join that calculates donor_segment_overall & years_consecutive
   * by grouping contributions by fy and calculating from latest donation fy.
   *
   * @param string $where
   * @param string $entityID
   *
   * @return string
   */
  protected function getSegmentRollupJoin(string $where, string $entityID): string {
    return "
  LEFT JOIN (
    SELECT contact_id,
      last_fy,
      MAX(IF(fy >= last_fy - 2, fy_total, 0)) AS highest_3y_window_total,
      SUM(streak_group = last_fy + 1) AS years_consecutive
    FROM (
      SELECT contact_id, fy, fy_total,
        MAX(fy) OVER (PARTITION BY contact_id) AS last_fy,
        fy + ROW_NUMBER() OVER (PARTITION BY contact_id ORDER BY fy DESC) AS streak_group
      FROM (
        SELECT c.contact_id,
          YEAR(c.receive_date) - (MONTH(c.receive_date) < 7) AS fy,
          SUM(c.total_amount) AS fy_total
        FROM civicrm_contribution c
        USE INDEX(FK_civicrm_contribution_contact_id)
        WHERE $where
          AND c.contribution_status_id = 1
          AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)
          AND c.total_amount > 0
        GROUP BY c.contact_id, fy
      ) fy_totals
    ) fy_islands
    GROUP BY contact_id, last_fy
  ) consecutive_years ON consecutive_years.contact_id = $entityID";
  }

  /**
   * Run a back fill on WMF donor data for this source table.
   *
   * This will recalculate and update WMF donor data where the where clause is
   * met.
   *
   * @throws \CRM_Core_Exception
   */
  public function updateWMFDonorData(): void {
    $this->triggerContext = FALSE;
    if (!$this->getWhereClause()) {
      throw new \CRM_Core_Exception('This update requires a WHERE clause');
    }
    if (!empty($this->getCalculatedFields())) {
      \CRM_Core_DAO::executeQuery($this->getUpdateWMFDonorSql());
    }
  }

  /**
   * Get the financial type for endowment.
   *
   * @return int|null
   */
  protected function getEndowmentFinancialType(): ?int {
    return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
  }

  /**
   * Get the select clause for the old donor segment status.
   *
   * Currently a placeholder.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getOldSegmentStatusSelect(): string {
    if (!$this->oldStatusSelectSQL) {
      $options = $this->getOldDonorStatusOptions();
      $this->oldStatusSelectSQL = "\n        CASE";
      foreach ($options as $option) {
        if (!empty($option['sql_select'])) {
          $this->oldStatusSelectSQL .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->oldStatusSelectSQL .= '
        ELSE 1000
        END as donor_status_id';
    }
    return $this->oldStatusSelectSQL;
  }

  /**
   * Get the select clause for the old donor segment.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getOldSegmentSelect(): string {
    if (!$this->oldSegmentSelectSQL) {
      $options = $this->getOldDonorSegmentOptions();
      $this->oldSegmentSelectSQL = "\n        CASE";
      foreach ($options as $option) {
        if (!empty($option['sql_select'])) {
          $this->oldSegmentSelectSQL .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->oldSegmentSelectSQL .= '
        ELSE 1000
        END as donor_segment_id';
    }
    return $this->oldSegmentSelectSQL;
  }

  /**
   * Get the select clause for the Overall or OTG donor status.
   *
   * @param string $field
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getOverallOTGDonorStatusSelect(string $field): string {
    if (!isset($this->statusSelectSQL[$field])) {
      $this->statusSelectSQL[$field] = "\n        CASE";
      foreach ($this->getSpecifiedDonorStatusOptions($field) as $option) {
        if (!empty($option['sql_select'])) {
          $this->statusSelectSQL[$field] .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->statusSelectSQL[$field] .= '
        ELSE 99
        END as ' . $field;
    }
    return $this->statusSelectSQL[$field];
  }

  /**
   * Get the select clause for the donor segment.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getSegmentSelect(): string {
    if (!$this->segmentSelectSQL) {
      $this->segmentSelectSQL = 'MAX(CASE';
      foreach ($this->getDonorSegmentOptions() as $option) {
        if (isset($option['threshold'])) {
          $this->segmentSelectSQL .= "\n         WHEN consecutive_years.highest_3y_window_total >= {$option['threshold']} THEN {$option['value']}";
        }
      }
      $this->segmentSelectSQL .= "\n         ELSE 990\n       END) as donor_segment_overall";
    }
    return $this->segmentSelectSQL;
  }

  /**
   * Get explanations of the old segment options.
   *
   * This is primary for fr-tech brain injury prevention during QA.
   * It's not clear the longer term role of this function.
   *
   * @throws \CRM_Core_Exception
   */
  public function getOldSegmentOptionDetails($value): string {
    foreach ($this->getOldDonorSegmentOptions() as $segmentOption) {
      if ($segmentOption['value'] === $value) {
        return $segmentOption['static_description'] . ' ' . $segmentOption['description'];
      }
    }
    return '';
  }

  /**
   * Get the donor segment options by field type.
   *
   * @param string $field
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getSpecifiedDonorStatusOptions(string $field): array {
    if (isset($this->donorStatusOptions[$field])) {
      return $this->donorStatusOptions[$field];
    }
    switch ($field) {
      case 'donor_status_overall':
      case 'donor_status_otg':
        return $this->donorStatusOptions[$field] = $this->getOverallOTGDonorStatusOptions($field);
      case 'donor_status_recur_overall':
        return $this->donorStatusOptions[$field] = $this->getRecurringDonorStatusOptions('overall');
      case 'donor_status_recur_month':
        return $this->donorStatusOptions[$field] = $this->getRecurringDonorStatusOptions('month');
      case 'donor_status_recur_year':
        return $this->donorStatusOptions[$field] = $this->getRecurringDonorStatusOptions('year');
      default:
        throw new \CRM_Core_Exception("Unknown donor status field $field");
    }
  }


  /**
   * Get the options for the overall and OTG donor status fields.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/17Pc1tIvqol6XJhuu97RlOfwy_Avbb9MEaRwhy2F529I/edit?usp=sharing
   *
   * @param string $field
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  protected function getOverallOTGDonorStatusOptions(string $field): array {
    $details = [
      10 => [
        'label' => 'Consecutive this year',
        'static_description' => 'Gave this year and the year before',
        'value' => 10,
        'name' => 'consecutive',
        'criteria' => [
          // multiple ranges are AND rather than OR
          'multiple_range' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime()],
            ['from' => $this->getFinancialYearStartDateTime(-1), 'to' => $this->getFinancialYearEndDateTime(-1)],
          ],
        ],
      ],
      20 => [
        'label' => 'Reactivated this year',
        'static_description' => 'Gave this year, after previously being a lapsed status',
        'value' => 20,
        'name' => 'reactivated',
        'criteria' => [
          'multiple_range' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime()],
            ['from' => $this->getFinancialYearStartDateTime(-25), 'to' => $this->getFinancialYearEndDateTime(-2)],
          ],
        ],
      ],
      30 => [
        'label' => 'New this year',
        'static_description' => 'First donation this year',
        'value' => 30,
        'name' => 'new',
        'criteria' => [
          // can't we just fall through here instead?
          'first_donation' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime()],
          ],
        ],
      ],
      40 => [
        'label' => 'Consecutive last year',
        'static_description' => "Previously consecutive but didn't give this year",
        'value' => 40,
        'name' => 'lybunt',
        'criteria' => [
          'multiple_range' => [
            ['from' => $this->getFinancialYearStartDateTime(-1), 'to' => $this->getFinancialYearEndDateTime(-1)],
            ['from' => $this->getFinancialYearStartDateTime(-2), 'to' => $this->getFinancialYearEndDateTime(-2)],
          ],
        ],
      ],
      50 => [
        'label' => 'Reactivated last year',
        'static_description' => 'Reactivated last year, has not given this year',
        'value' => 50,
        'name' => 'reactivated_last_year',
        'criteria' => [
          'multiple_range' => [
            ['from' => $this->getFinancialYearStartDateTime(-1), 'to' => $this->getFinancialYearEndDateTime(-1)],
            ['from' => $this->getFinancialYearStartDateTime(-25), 'to' => $this->getFinancialYearEndDateTime(-3)],
          ],
        ],
      ],
      60 => [
        'label' => 'New last year',
        'static_description' => 'New donor last year, has not given this year',
        'value' => 60,
        'name' => 'new_last_year',
        'criteria' => [
          'range' => [
            ['from' => $this->getFinancialYearStartDateTime(-1), 'to' => $this->getFinancialYearEndDateTime(-1)],
          ],
        ],
      ],
      70 => [
        'label' => 'Lapsed',
        'static_description' => 'Last gave in the year before last',
        'value' => 70,
        'name' => 'lapsed',
        'criteria' => [
          'range' => [
            ['from' => $this->getFinancialYearStartDateTime(-2), 'to' => $this->getFinancialYearEndDateTime(-2)],
          ],
        ],
      ],
      80 => [
        'label' => 'Deep lapsed',
        'static_description' => 'Last gave between 2 & 5 years ago',
        'value' => 80,
        'name' => 'deep_lapsed',
        'criteria' => [
          'range' => [
            ['from' => $this->getFinancialYearStartDateTime(-5), 'to' => $this->getFinancialYearEndDateTime(-3)],
          ],
        ],
      ],
      90 => [
        'label' => 'Ultra lapsed',
        'static_description' => 'Last gave prior to 5 years ago',
        'value' => 90,
        'name' => 'ultra_lapsed',
        'criteria' => [
          'range' => [
            ['from' => $this->getFinancialYearEndDateTime(-25), 'to' => $this->getFinancialYearEndDateTime(-6)],
          ],
        ],
      ],
      99 => [
        'label' => 'Non donor',
        'static_description' => 'Has never given',
        'value' => 99,
        'name' => 'non_donor',
      ],
    ];
    foreach ($details as $index => $detail) {
      if ($field === 'donor_status_otg' && !empty($detail['criteria'])) {
        $criteriaKey = array_key_first($detail['criteria']);
        foreach ($detail['criteria'][$criteriaKey] as $rangeKey => $range) {
          $details[$index]['criteria'][$criteriaKey][$rangeKey]['additional_criteria'] = ['contribution_recur_id IS NULL'];
        }
      }
      if (!empty($detail['criteria'])) {
        $this->addCriteriaInterpretation($details[$index]);
      }
      else {
        $details[$index]['description'] = $detail['static_description'];
      }
    }
    return $details;
  }

  /**
   * Get the options for the recurring donor status fields.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/17Pc1tIvqol6XJhuu97RlOfwy_Avbb9MEaRwhy2F529I/edit?usp=sharing
   *
   * @param string $frequencyUnit
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  protected function getRecurringDonorStatusOptions(string $frequencyUnit): array {
    $frequency = ['year' => 'annual ', 'month' => 'monthly ', 'overall' => ''][$frequencyUnit];
    return [
      15 => [
        'label' => 'Active',
        'description' => "Has an active {$frequency}recurring donation",
        'value' => 15,
        'name' => 'active',
      ],
      25 => [
        'label' => 'New',
        'description' => "First year as a {$frequency}recurring donor",
        'value' => 25,
        'name' => 'new',
      ],
      35 => [
        'label' => 'Paused',
        'description' => "All non-cancelled {$frequency}recurring donations paused",
        'value' => 35,
        'name' => 'paused',
      ],
      45 => [
        'label' => 'Failing',
        'description' => "Has a {$frequency}recurring donations in failing flow",
        'value' => 45,
        'name' => 'failing',
      ],
      55 => [
        'label' => 'Failed',
        'description' => "Has a {$frequency}recurring donation that has failed because we couldn't process the payment",
        'value' => 55,
        'name' => 'failed',
      ],
      65 => [
        'label' => 'Cancelled',
        'description' => "Has {$frequency}recurring donation that was cancelled per donor request",
        'value' => 65,
        'name' => 'cancelled',
      ],
      95 => [
        'label' => 'Never',
        'description' => "Has never made a {$frequency}recurring donation",
        'value' => 95,
        'name' => 'never',
      ],
    ];
  }

  /**
   * Get the select clause for the recurring donor statuses.
   *
   * @param string $frequencyUnit
   * @return string
   */
  protected function getRecurringDonorStatusSelect(string $frequencyUnit): string {
    $s = array_flip(\CRM_Core_PseudoConstant::get(
      'CRM_Contribute_BAO_ContributionRecur',
      'contribution_status_id',
      ['labelColumn' => 'name']
    ));
    $frequencyFilter = $frequencyUnit === 'overall' ? '' : " AND c.frequency_unit = '$frequencyUnit'";
    if ($frequencyUnit === 'overall') {
      $pausedUntil = 'CASE c.frequency_unit';
      foreach (['month', 'year'] as $unit) {
        $pausedUntil .= " WHEN '$unit' THEN DATE_ADD(NOW(), INTERVAL 1 $unit)";
      }
      $pausedUntil .= ' END';
    }
    else {
      $pausedUntil = "DATE_ADD(NOW(), INTERVAL 1 $frequencyUnit)";
    }
    return "CASE
      -- Paused status (35), no scheduled payments within the recurring's frequency unit
      WHEN MIN(CASE WHEN c.contribution_status_id IN ({$s['Pending']},{$s['In Progress']},{$s['Processing']})$frequencyFilter
              THEN c.next_sched_contribution_date > $pausedUntil
         END) = 1 THEN 35
      -- Active Status (15) Has an active recurring and was already a recurring donor prior to this financial year
      WHEN MAX(c.contribution_status_id IN ({$s['Pending']},{$s['In Progress']},{$s['Processing']})$frequencyFilter) = 1
       AND MAX(c.start_date < '{$this->getFinancialYearStartDateTime()}'$frequencyFilter) = 1 THEN 15
       -- New(25) has an active recurring and no recurrings of this frequency started before this year (or they would have been snagged in 15 above)
      WHEN MAX(c.contribution_status_id IN ({$s['Pending']},{$s['In Progress']},{$s['Processing']})$frequencyFilter) = 1 THEN 25
      WHEN MAX(c.contribution_status_id = {$s['Failing']}$frequencyFilter) = 1 THEN 45
      WHEN MAX(c.contribution_status_id = {$s['Failed']}$frequencyFilter) = 1 THEN 55
      WHEN MAX(c.contribution_status_id IN ({$s['Cancelled']},{$s['Completed']})$frequencyFilter) = 1 THEN 65
      ELSE 95
      END AS donor_status_recur_$frequencyUnit";
  }

  /**
   * Get the options for the old donor status field.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/1qM36MeKWyOENl-iR5umuLph5HLHG6W_6c46xJUdE3QY/edit
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getOldDonorStatusOptions(): array {
    $midTierAndMajorGiftsExclusionRange = [
      ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime(), 'max_total' => 1000],
      ['from' => $this->getFinancialYearStartDateTime(-1), 'to' => $this->getFinancialYearEndDateTime(-1), 'max_total' => 1000],
      ['from' => $this->getFinancialYearStartDateTime(-2), 'to' => $this->getFinancialYearEndDateTime(-2), 'max_total' => 1000],
      ['from' => $this->getFinancialYearStartDateTime(-3), 'to' => $this->getFinancialYearEndDateTime(-3), 'max_total' => 1000],
      ['from' => $this->getFinancialYearStartDateTime(-4), 'to' => $this->getFinancialYearEndDateTime(-4), 'max_total' => 1000],
      ['from' => $this->getFinancialYearStartDateTime(-5), 'to' => $this->getFinancialYearEndDateTime(-5), 'max_total' => 1000],
    ];
    // Note that the values are what are stored in the database, with the labels. We
    // want the recurring statuses to have high values so that filters can do things
    // like 'status < 40 to get all donors from this financial year.
    // However, the processing order is such that recurring donors should be processed
    // first so that any donors who are not 'mid tier excluded' - ie gave 1000
    // or more in one of the last 5 financial years or this one to-date -
    // will get a recurring status in preference to a one-off status.
    $details = [
      2 => [
        'name' => 'recurring_active',
        'label' => 'Active Recurring',
        'value' => 2,
        'static_description' => 'gave monthly recurring within last month',
        'criteria' => [
          'multiple_range' => array_merge([
            [
              'from' => '1 months ago',
              'to' => $this->getFinancialYearEndDateTime(),
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL', 'annual_recur.id IS NULL'],
            ],
          ], $midTierAndMajorGiftsExclusionRange),
        ],
      ],
      4 => [
        'label' => 'Delinquent Recurring',
        'static_description' => 'gave monthly recurring between 1 and 3 months ago',
        'value' => 4,
        'name' => 'recurring_delinquent',
        'criteria' => [
          'multiple_range' => array_merge([
            [
              'from' => '3 months ago',
              'to' => '1 months ago',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL', 'annual_recur.id IS NULL'],
            ],
          ], $midTierAndMajorGiftsExclusionRange),
        ],
      ],
      6 => [
        'label' => 'Recent lapsed Recurring',
        'static_description' => 'gave monthly recurring between 3 and 6 months ago',
        'value' => 6,
        'name' => 'recurring_lapsed_recent',
        'criteria' => [
          'multiple_range' => array_merge([
            [
              'from' => '6 months ago',
              'to' => '3 months ago',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL', 'annual_recur.id IS NULL'],
            ],
          ], $midTierAndMajorGiftsExclusionRange),
        ],
      ],
      8 => [
        'label' => 'Deep lapsed Recurring',
        'static_description' => 'gave monthly recurring between 6 and 36 months ago',
        'value' => 8,
        'name' => 'recurring_deep_lapsed',
        'criteria' => [
          'multiple_range' => array_merge([
            [
              'from' => '36 months ago',
              'to' => '6 months ago',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL', 'annual_recur.id IS NULL'],
            ],
          ], $midTierAndMajorGiftsExclusionRange),
        ],
      ],
      12 => [
        'name' => 'annual_recurring_active',
        'label' => 'Active Annual Recurring',
        'value' => 12,
        'static_description' => 'has an active annual recurring plan',
        'criteria' => [
          'multiple_range' => $midTierAndMajorGiftsExclusionRange,
          'recurring' => [
            'annual_recur.contribution_status_id NOT IN (1, 3, 4)',
          ],
        ],
      ],
      14 => [
        'name' => 'annual_recurring_delinquent',
        'label' => 'Delinquent Annual Recurring',
        'value' => 14,
        'static_description' => 'their annual recurring plan was cancelled within the last 3 months',
        'criteria' => [
          'multiple_range' => $midTierAndMajorGiftsExclusionRange,
          'recurring' => [
            'annual_recur.end_date > NOW() - INTERVAL 3 MONTH',
            'annual_recur.cancel_date > NOW() - INTERVAL 3 MONTH',
          ],
        ],
      ],
      16 => [
        'name' => 'annual_recurring_lapsed',
        'label' => 'Lapsed Annual Recurring',
        'value' => 16,
        'static_description' => 'their annual recurring plan was cancelled between 3 and 13 months ago',
        'criteria' => [
          'multiple_range' => $midTierAndMajorGiftsExclusionRange,
          'recurring' => [
            'annual_recur.cancel_date > NOW() - INTERVAL 13 MONTH',
            'annual_recur.end_date > NOW() - INTERVAL 13 MONTH',
          ],
        ],
      ],
      20 => [
        'label' => 'Consecutive',
        'static_description' => 'gave last financial year and this financial year to date',
        'value' => 20,
        'name' => 'consecutive',
        'criteria' => [
          // multiple ranges are AND rather than OR
          'multiple_range' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime(), 'total' => 0.01],
            [
              'from' => $this->getFinancialYearStartDateTime(-1),
              'to' => $this->getFinancialYearEndDateTime(-1),
              'total' => 0.01,
            ],
          ],
        ],
      ],
      25 => [
        'label' => 'New',
        'static_description' => 'first donation this FY',
        'value' => 25,
        'name' => 'new',
        'criteria' => [
          'first_donation' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime(), 'total' => 0.01],
          ],
        ],
      ],
      30 => [
        'name' => 'active',
        'label' => 'Active',
        'value' => 30,
        'static_description' => 'gave in this FY',
        'criteria' => [
          'range' => [
            ['from' => $this->getFinancialYearStartDateTime(), 'to' => $this->getFinancialYearEndDateTime(), 'total' => 0.01],
          ],
        ],
      ],
      35 => [
        'label' => 'Lybunt',
        'static_description' => 'gave last financial year but NOT this financial year to date',
        'value' => 35,
        'name' => 'lybunt',
        'criteria' => [
          'range' => [
            [
              // Note we don't need to confirm they did NOT give this financial year
              // as they would have already triggered the previous 'consequetive' criteria
              'from' => $this->getFinancialYearStartDateTime(-1),
              'to' => $this->getFinancialYearEndDateTime(-1),
              'total' => 0.01,
            ],
          ],
        ],
      ],
      50 => [
        'label' => 'Lapsed',
        'static_description' => 'last gave in the financial year before last',
        'value' => 50,
        'name' => 'lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => $this->getFinancialYearStartDateTime(-2),
              'to' => $this->getFinancialYearEndDateTime(-2),
              'total' => 0.01,
            ],
          ],
        ],
      ],
      60 => [
        'label' => 'Deep Lapsed',
        'static_description' => 'last gave between 2 & 5 financial years ago',
        'value' => 60,
        'name' => 'deep_lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => $this->getFinancialYearStartDateTime(-5),
              'to' => $this->getFinancialYearStartDateTime(-2),
              'total' => 0.01,
            ],
          ],
        ],
      ],
      70 => [
        'label' => 'Ultra lapsed',
        'static_description' => 'gave prior to 5 financial years ago',
        'value' => 70,
        'name' => 'ultra_lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => $this->getFinancialYearEndDateTime(-25),
              'to' => $this->getFinancialYearEndDateTime(-6),
              'total' => 0.01,
            ],
          ],
        ],
      ],
      1000 => [
        'label' => 'Non Donor',
        'static_description' => 'no donations in last 200 months',
        'value' => 1000,
        'name' => 'non-donor',
      ],
    ];
    foreach ($details as $index => $detail) {
      if (!empty($detail['criteria'])) {
        $this->addCriteriaInterpretation($details[$index]);
      }
      else {
        $details[$index]['description'] = $detail['static_description'];
      }
    }
    return $details;
  }

  /**
   * Get the options for the old donor segment field.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/1qM36MeKWyOENl-iR5umuLph5HLHG6W_6c46xJUdE3QY/edit
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getOldDonorSegmentOptions(): array {
    $financialYears = [
      'this' => ['start' => $this->getFinancialYearStartDateTime(), 'end' => $this->getFinancialYearEndDateTime()],
      -1 => ['start' => $this->getFinancialYearStartDateTime(-1), 'end' => $this->getFinancialYearEndDateTime(-1)],
      -2 => ['start' => $this->getFinancialYearStartDateTime(-2), 'end' => $this->getFinancialYearEndDateTime(-2)],
      -3 => ['start' => $this->getFinancialYearStartDateTime(-3), 'end' => $this->getFinancialYearEndDateTime(-3)],
      -4 => ['start' => $this->getFinancialYearStartDateTime(-4), 'end' => $this->getFinancialYearEndDateTime(-4)],
      -5 => ['start' => $this->getFinancialYearStartDateTime(-5), 'end' => $this->getFinancialYearEndDateTime(-5)],
    ];

    $details = [
      100 => [
        'label' => 'Major Donor',
        'value' => 100,
        // Use for triggers instead of the dynamic description, which will date.
        'static_description' => 'has given 10,000+ in one of the past 5 financial years, or in the current financial year so far',
        'name' => 'major_donor',
        'criteria' => [
          'range' => [
            ['from' => $financialYears['this']['start'], 'to' => $this->getFinancialYearEndDateTime(), 'total' => 10000],
            ['from' => $financialYears[-1]['start'], 'to' => $financialYears[-1]['end'], 'total' => 10000],
            ['from' => $financialYears[-2]['start'], 'to' => $financialYears[-2]['end'], 'total' => 10000],
            ['from' => $financialYears[-3]['start'], 'to' => $financialYears[-3]['end'], 'total' => 10000],
            ['from' => $financialYears[-4]['start'], 'to' => $financialYears[-4]['end'], 'total' => 10000],
            ['from' => $financialYears[-5]['start'], 'to' => $financialYears[-5]['end'], 'total' => 10000],
          ],
        ],
      ],
      200 => [
        'label' => 'Mid Tier',
        'value' => 200,
        'static_description' => 'has given 1,000+  in one of the past 5 financial years, or in the current financial year so far',
        'name' => 'mid_tier',
        'criteria' => [
          'range' => [
            ['from' => $financialYears['this']['start'], 'to' => $this->getFinancialYearEndDateTime(), 'total' => 1000],
            ['from' => $financialYears[-1]['start'], 'to' => $financialYears[-1]['end'], 'total' => 1000],
            ['from' => $financialYears[-2]['start'], 'to' => $financialYears[-2]['end'], 'total' => 1000],
            ['from' => $financialYears[-3]['start'], 'to' => $financialYears[-3]['end'], 'total' => 1000],
            ['from' => $financialYears[-4]['start'], 'to' => $financialYears[-4]['end'], 'total' => 1000],
            ['from' => $financialYears[-5]['start'], 'to' => $financialYears[-5]['end'], 'total' => 1000],
          ],
        ],
      ],
      300 => [
        'label' => 'Mid-Value Prospect',
        'value' => 300,
        'static_description' => 'has given 250+ in one of the past 5 financial years, or in the current financial year so far',
        'name' => 'mid_value',
        'criteria' => [
          'range' => [
            ['from' => $financialYears['this']['start'], 'to' => $this->getFinancialYearEndDateTime(), 'total' => 250],
            ['from' => $financialYears[-1]['start'], 'to' => $financialYears[-1]['end'], 'total' => 250],
            ['from' => $financialYears[-2]['start'], 'to' => $financialYears[-2]['end'], 'total' => 250],
            ['from' => $financialYears[-3]['start'], 'to' => $financialYears[-3]['end'], 'total' => 250],
            ['from' => $financialYears[-4]['start'], 'to' => $financialYears[-4]['end'], 'total' => 250],
            ['from' => $financialYears[-5]['start'], 'to' => $financialYears[-5]['end'], 'total' => 250],
          ],
        ],
      ],
      400 => [
        'label' => 'Recurring donor',
        'value' => 400,
        'static_description' => 'has made a monthly recurring donation in last 36 months',
        'name' => 'recurring',
        'criteria' => [
          'range' => [
            ['from' => '36 months ago', 'to' => $this->getFinancialYearEndDateTime(), 'total' => 0.01, 'additional_criteria' => ['contribution_recur_id IS NOT NULL', 'annual_recur.id IS NULL']],
          ],
        ],
      ],
      450 => [
        'label' => 'Recurring annual donor',
        'value' => 450,
        'static_description' => 'has an annual recurring plan that is active or was active in the last 13 months',
        'name' => 'recurring_annual',
        'criteria' => [
          'recurring' => [
            'annual_recur.contribution_status_id NOT IN (1, 3, 4)',
            'annual_recur.cancel_date > NOW() - INTERVAL 13 MONTH',
            'annual_recur.end_date > NOW() - INTERVAL 13 MONTH',
          ],
        ],
      ],
      500 => [
        'label' => 'Grassroots Plus Donor',
        'value' => 500,
        'static_description' => 'has given 50+  in one of the past 5 financial years, or in the current financial year so far',
        'name' => 'grass_roots_plus',
        'criteria' => [
          'range' => [
            ['from' => $financialYears['this']['start'], 'to' => $this->getFinancialYearEndDateTime(), 'total' => 50],
            ['from' => $financialYears[-1]['start'], 'to' => $financialYears[-1]['end'], 'total' => 50],
            ['from' => $financialYears[-2]['start'], 'to' => $financialYears[-2]['end'], 'total' => 50],
            ['from' => $financialYears[-3]['start'], 'to' => $financialYears[-3]['end'], 'total' => 50],
            ['from' => $financialYears[-4]['start'], 'to' => $financialYears[-4]['end'], 'total' => 50],
            ['from' => $financialYears[-5]['start'], 'to' => $financialYears[-5]['end'], 'total' => 50],
          ],
        ],
      ],
      600 => [
        'label' => 'Grassroots Donor',
        'value' => 600,
        'static_description' => 'has given in the last 5 financial years (or the current one)',
        'name' => 'grass_roots',
        'criteria' => [
          'range' => [
            ['from' => $financialYears[-5]['start'], 'to' => $this->getFinancialYearEndDateTime(), 'total' => .01],
          ],
        ],
      ],
      900 => [
        'label' => 'All other Donors',
        'value' => 900,
        'static_description' => 'has given but not in the last 5 financial years (or the current one)',
        'name' => 'other_donor',
        'criteria' => [
          'range' => [
            // 300 months is our forever
            ['from' => '300 months ago', 'to' => $this->getFinancialYearEndDateTime(), 'total' => .01],
          ],
        ],
      ],
      1000 => [
        'label' => 'Non Donor',
        'value' => 1000,
        'static_description' => 'this can not be calculated with the others. We will have to populate once & then ?',
        'name' => 'non_donor',
        'description' => 'never donated',
      ],
    ];
    foreach ($details as $index => $detail) {
      if (!empty($detail['criteria'])) {
        $this->addCriteriaInterpretation($details[$index]);
      }
    }
    return $details;
  }

  /**
   * Get the options for the donor segment field.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/17Pc1tIvqol6XJhuu97RlOfwy_Avbb9MEaRwhy2F529I/edit?usp=sharing
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getDonorSegmentOptions(): array {
    return [
      100 => [
        'label' => 'Major Donor',
        'value' => 100,
        'name' => 'major_donor',
        'threshold' => 10000,
        'description' => 'Has given $10,000+ in the last fiscal year in which they gave or the 2 years before that',
      ],
      200 => [
        'label' => 'Mid Value Plus Donor',
        'value' => 200,
        'name' => 'mid_value_plus',
        'threshold' => 5000,
        'description' => 'Has given $5,000+ in the last fiscal year in which they gave or the 2 years before that',
      ],
      300 => [
        'label' => 'Mid Value Donor',
        'value' => 300,
        'name' => 'mid_value',
        'threshold' => 1000,
        'description' => 'Has given $1,000+ in the last fiscal year in which they gave or the 2 years before that',
      ],
      400 => [
        'label' => 'Mid Value Prospect',
        'value' => 400,
        'name' => 'mid_value_prospect',
        'threshold' => 250,
        'description' => 'Has given $250+ in the last fiscal year in which they gave or the 2 years before that',
      ],
      500 => [
        'label' => 'Grassroots Plus Donor',
        'value' => 500,
        'name' => 'grass_roots_plus',
        'threshold' => 50,
        'description' => 'Has given $50+ in the last fiscal year in which they gave or the 2 years before that',
      ],
      600 => [
        'label' => 'Grassroots Donor',
        'value' => 600,
        'name' => 'grass_roots',
        'threshold' => 0.01,
        'description' => 'Has given',
      ],
      990 => [
        'label' => 'Non Donor',
        'value' => 990,
        'name' => 'non_donor',
        'description' => 'Has never donated',
      ],
    ];
  }

  /**
   * Converts a string date description to sql.
   *
   * E.g '12 months ago' becomes 'NOW() - INTERVAL 12 MONTH'
   *
   * The reason to do it like this is it is already so hard to process
   * that this allows us to use 'plain text' when describing the criteria
   * and hopefully a couple of brain cells will live to code another day.
   *
   * @param string $textDateOffset
   *
   * @return string
   */
  protected function convertDateOffSetToSQL(string $textDateOffset): string {
    if ($textDateOffset === $this->getFinancialYearEndDateTime()) {
      return "'" . $this->getFinancialYearEndDateTime() . "'";
    }
    $split = explode(' ', $textDateOffset);
    if (empty($split[1]) || strpos($split[1], ':') !== FALSE) {
      // We have an actual date, possibly including a time portion after the space.
      return "'" . $textDateOffset . "'";
    }
    $offset = $split[0];
    $interval = strtoupper($split[1]);
    if ($interval === 'MONTHS') {
      $interval = 'MONTH';
    }
    // If the date now is after our hard-coded financial year end then we want to
    // use dates relative to the financial year end. The goal here is to make the
    // triggers 'expire' at the end of the financial year, so we can back up & reset.
    // We might change this after discussion but the 'safe' position is to freeze
    // the fields when we roll over the year until we take action to reload the
    // triggers.
    return "IF (NOW() < '" . $this->getFinancialYearEndDateTime() . "', NOW() - INTERVAL $offset $interval, '" . $this->getFinancialYearEndDateTime() . "' - INTERVAL $offset $interval)";
  }

  /**
   * Get the crazy insane clause for this range.
   *
   * @param array $range
   *
   * @return string
   */
  protected function getRangeClause(array $range): string {
    $additionalCriteria = empty($range['additional_criteria']) ? '' : (implode(' AND ', $range['additional_criteria'])) . ' AND ';
    return "COALESCE( IF($additionalCriteria receive_date " .
      "BETWEEN (" . $this->convertDateOffsetToSQL($range['from']) . ") AND (" . $this->convertDateOffsetToSQL($range['to']) . '), total_amount, 0), 0)';
  }

  /**
   * @param $range
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getTextClause($range): string {
    $comparison = isset($range['max_total']) ? ' less than ' : 'at least ';
    $amount = $range['total'] ?? $range['max_total'] ?? '0.01';
    $textClause = $comparison . \Civi::format()->money($amount, 'USD', 'en-US') .
      ' between ' . date('Y-m-d H:i:s', strtotime($range['from'])) . ' and ' .
      date('Y-m-d H:i:s', strtotime($range['to']));
    if (!empty($range['additional_criteria'])) {
      // Once we get rid of the old segments, we can just set this to always be 'not recurring'
      // if there is an additional criteria.
      $textClause .= ($range['additional_criteria'] === ['contribution_recur_id IS NULL']) ? ' and the donation is not recurring' : ' and donation is recurring';
    }
    return $textClause;
  }

  /**
   * @param array $detail
   *
   * @throws \CRM_Core_Exception
   */
  protected function addCriteriaInterpretation(array &$detail): void {
    $clauses = '';
    $dynamicDescription = '';
    // For now we are safe that only one type of range exists - ie standard
    // 'or range', multiple_range (and) or first_donation. If that changes the below
    // will need a re-write, if it doesn't get re-written first .. in another pass.
    if (!empty($detail['criteria']['range'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['range'] as $range) {
        $rangeClauses[] = 'SUM(' . $this->getRangeClause($range) . ')' . $this->getValueComparisonClause($range);
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode("\n         OR ", $rangeClauses);
      $dynamicDescription = implode(" OR \n", $textClauses);
    }
    if (!empty($detail['criteria']['multiple_range'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['multiple_range'] as $range) {
        $rangeClauses[] = 'SUM(' . $this->getRangeClause($range) . ')' . $this->getValueComparisonClause($range);
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode("\n         AND ", $rangeClauses);
      $dynamicDescription = implode(" AND \n", $textClauses);
    }
    if (!empty($detail['criteria']['first_donation'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['first_donation'] as $range) {
        $rangeClauses[] = 'MIN(' . $this->getRangeClause($range) . ')' . $this->getValueComparisonClause($range);
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode("\n         OR ", $rangeClauses);
      $dynamicDescription = implode(" OR \n", $textClauses);
    }
    if (!empty($detail['criteria']['recurring'])) {
      $clauses = 'MAX(' . implode(' OR ', $detail['criteria']['recurring']) . ')' . ($clauses ? ' AND (' . $clauses . ')' : '');
      $detail['description'] = $detail['static_description'] . ($dynamicDescription ? " AND\n" . $dynamicDescription : '');
    }
    else {
      $detail['description'] = $detail['static_description'] . " - ie\n" . $dynamicDescription;
    }
    $sqlSelect = "
         WHEN (
         --  {$detail['label']}: {$detail['static_description']}
         $clauses
        )";
    $detail['sql_select'] = $sqlSelect;
  }

  /**
   * @return int
   */
  protected function getCurrentFinancialYearStartYear(): int {
    $currentMonth = date('m');
    return (int) ($currentMonth < 7 ? (date('Y') - 1) : date('Y'));
  }

  /**
   * Ge the date time string for the start of the financial year.
   *
   * By defaults this will be the current financial year, or an offset can be passed
   *
   * @param int $offset
   *  e.g. -1 would return the start date of the last financial year.
   *
   * @return string
   */
  protected function getFinancialYearStartDateTime(int $offset = 0): string {
    $currentFinancialYearStart = $this->getCurrentFinancialYearStartYear() . '-07-01 00:00:00';
    if (!$offset) {
      return $currentFinancialYearStart;
    }
    return date('Y-m-d H:i:s', strtotime($offset . ' year', strtotime($currentFinancialYearStart)));
  }

  /**
   * Ge the date time string for the start of the financial year.
   *
   * By defaults this will be the current financial year, or an offset can be passed
   *
   * @param int $offset
   *  e.g. -1 would return the start date of the last financial year.
   *
   * @return string
   */
  protected function getFinancialYearEndDateTime(int $offset = 0): string {
    $currentFinancialYearEndDateTime = ($this->getCurrentFinancialYearStartYear() + 1) . '-06-30 23:59:59.9999';

    if (!$offset) {
      return $currentFinancialYearEndDateTime;
    }
    return date('Y-m-d H:i:s', strtotime($offset . ' year', strtotime($currentFinancialYearEndDateTime)));
  }

  /**
   * Get Value comparison clause.
   *
   * If no total or max_total supplied, return one cent or more.
   *
   * @param array $criteria Holds one of
   *   - total
   *   - max_total
   *
   * @return string
   *   e.g '> 1000'
   * @throws \CRM_Core_Exception
   */
  protected function getValueComparisonClause(array $criteria): string {
    if (isset($criteria['total'])) {
      return ($criteria['total'] === 0 ? ' > ' : ' >= ') . $criteria['total'];
    }
    elseif (isset($criteria['max_total'])) {
      return ' < ' . $criteria['max_total'];
    }
    else {
      return ' >= 0.01';
    }
  }

}

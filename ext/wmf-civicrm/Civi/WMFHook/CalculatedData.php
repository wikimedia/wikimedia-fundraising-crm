<?php
// Class to hold wmf functionality that alters permissions.

namespace Civi\WMFHook;

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Generic\Result;
use CRM_Core_PseudoConstant;

class CalculatedData extends TriggerHook {

  protected const WMF_MIN_ROLLUP_YEAR = 2018;

  protected const WMF_MAX_ROLLUP_YEAR = 2026;

  protected const WMF_MIN_CALENDER_YEAR = 2023;

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
  private $segmentSelectSQL;

  /**
   * SQL for the status selects.
   *
   * @var string
   */
  private $statusSelectSQL;

  /**
   * @param bool $triggerContext
   *
   * @return \Civi\WMFHook\CalculatedData
   */
  public function setTriggerContext(bool $triggerContext): self {
    $this->triggerContext = $triggerContext;
    return $this;
  }

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
   * @var array
   */
  protected $calculatedFields;

  /**
   * Get the select clauses for year field for use when GROUP BY is not in use.
   *
   * @var array
   */
  protected $yearFieldSelects;

  /**
   * Get the select clauses for year field for use when GROUP BY is in use.
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

  public function getCalculatedFields(): array {
    if ($this->calculatedFields === NULL) {
      $this->calculatedFields = [];
      $this->getWMFDonorFields();
    }
    return $this->calculatedFields;
  }

  /**
   * Get the available field options.
   *
   * @param string $fieldName
   *
   * @return array []
   * @throws \CRM_Core_Exception
   */
  public function getFieldOptions(string $fieldName): array {
    return $this->getWMFDonorFields()[$fieldName]['option_values'] ?? [];
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
  public function getTotalsFieldSelects(): array {
    if ($this->yearFieldSelects === NULL) {
      $this->yearFieldSelects = [];
      foreach ($this->getCalculatedFields() as $yearField) {
        if (($yearField['table_alias'] ?? 'totals') === 'totals') {
          $this->yearFieldSelects[$yearField['name']] = $yearField['select_clause'];
        }
      }
    }
    return $this->yearFieldSelects;
  }

  /**
   * Get select clauses for the year-based fields in non-Group By mode.
   *
   * @return array
   */
  public function getUpdateClauses(): array {
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
  public function getSelectsAggregate(): array {
    if ($this->selectsAggregate === NULL) {
      $this->selectsAggregate = [];
      foreach ($this->getCalculatedFields() as $calculatedField) {
        if (!empty($calculatedField['aggregate_select_clause'])) {
          $this->selectsAggregate[$calculatedField['name']] = $calculatedField['aggregate_select_clause'];
        }
        else {
          // When full group by is applied (which it is not currently on our prod but at some point...)
          // it is necessary to aggregate all fields in some way - MAX is a stand in for when there is
          // only one value of interest.
          $operator = $calculatedField['group_by_operator'] ?? 'MAX';
          $this->selectsAggregate[$calculatedField['name']] = $operator . '(' . $calculatedField['name'] . ") as " . $calculatedField['name'];
        }
      }
    }
    return $this->selectsAggregate;
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
  public function getWhereClause() {
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
  public function isTriggerContext(): string {
    return $this->triggerContext;
  }

  /**
   * Add triggers for our calculated custom fields.
   *
   * Whenever a contribution is updated the fields are re-calculated provided
   * the change is an update, a delete or an update which alters a relevant field
   * (contribution_status_id, receive_date, total_amount, contact_id, currency).
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
    if (!$tableName || $tableName === 'civicrm_contribution') {
      $sql = $this->getUpdateWMFDonorSql();

      $significantFields = ['contribution_status_id', 'total_amount', 'contact_id', 'receive_date', 'currency', 'financial_type_id'];
      $updateConditions = [];
      foreach ($significantFields as $significantField) {
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

      $insertSQL = ' IF ' . implode(' AND ', $requiredClauses) . ' THEN' . $sql . ' END IF; ';
      $updateSQL = ' IF ' . implode(' AND ', $requiredClauses) . ' AND (' . implode(' OR ', $updateConditions) . ' ) THEN' . $sql . ' END IF; ';
      $requiredClausesForOldClause = str_replace('NEW.', 'OLD.', implode(' AND ', $requiredClauses));
      $oldSql = str_replace('NEW.', 'OLD.', $sql);
      $updateOldSQL = ' IF ' . $requiredClausesForOldClause
        . ' AND (NEW.contact_id <> OLD.contact_id) THEN'
        . $oldSql . ' END IF; ';

      $deleteSql = ' IF ' . $requiredClausesForOldClause . ' THEN' . $oldSql . ' END IF; ';

      // We want to fire this trigger on insert, update and delete.
      $info[] = [
        'table' => 'civicrm_contribution',
        'when' => 'AFTER',
        'event' => 'INSERT',
        'sql' => $insertSQL,
      ];
      $info[] = [
        'table' => 'civicrm_contribution',
        'when' => 'AFTER',
        'event' => 'UPDATE',
        'sql' => $updateSQL,
      ];
      $info[] = [
        'table' => 'civicrm_contribution',
        'when' => 'AFTER',
        'event' => 'UPDATE',
        'sql' => $updateOldSQL,
      ];
      // For delete, we reference OLD.field instead of NEW.field
      $info[] = [
        'table' => 'civicrm_contribution',
        'when' => 'AFTER',
        'event' => 'DELETE',
        'sql' => $deleteSql,
      ];
    }
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
    $allFields = $this->getCalculatedFields();
    // Reduce calculated fields to the intersection.
    $this->calculatedFields = array_intersect_key($allFields, array_fill_keys($fieldsToShow, 1));

    // Put back join fields if needed for other fields, or to ensure there is at least one field present.
    if (empty($this->calculatedFields) || $this->isIncludeTable('latest')) {
      $this->calculatedFields['last_donation_date'] = $allFields['last_donation_date'];
    }
    if ($this->isIncludeTable('earliest')) {
      $this->calculatedFields['first_donation_date'] = $allFields['first_donation_date'];
    }
    if ($this->isIncludeTable('largest')) {
      $this->calculatedFields['largest_donation'] = $allFields['largest_donation'];
    }
  }

  /**
   * Get the tables / subqueries that are required.
   *
   * @return array
   */
  public function getRequiredTables(): array {
    $tables = [];
    foreach ($this->calculatedFields as $field) {
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
  public function isIncludeTable($tableAlias): bool {
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
    $this->calculatedFields = [
      'donor_segment_id' => [
        'name' => 'donor_segment_id',
        'column_name' => 'donor_segment_id',
        'label' => ts('Donor Segment'),
        'data_type' => 'Int',
        'default_value' => 1000,
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'select_clause' => $this->getSegmentSelect(),
        'option_values' => $this->getDonorSegmentOptions(),
      ],
      'donor_status_id' => [
        'name' => 'donor_status_id',
        'column_name' => 'donor_status_id',
        'label' => ts('Donor Status'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'select_clause' => $this->getSegmentStatusSelect(),
        'option_values' => $this->getDonorStatusOptions(),
      ],
      // Per https://phabricator.wikimedia.org/T222958#5323233
      // This is used in emails - and needs to not mix endowments & non-endowments
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
        'aggregate_select_clause' => 'MAX(COALESCE(x.original_amount,
 latest.total_amount,
 0)) as last_donation_amount',
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
        'aggregate_select_clause' => 'MAX(COALESCE(latest.total_amount,
 0)) as last_donation_usd',
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
        'aggregate_select_clause' => 'MAX(COALESCE(earliest.total_amount,
 0)) as first_donation_usd',
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
      'lifetime_usd_total' => [
        'name' => 'lifetime_usd_total',
        'column_name' => 'lifetime_usd_total',
        'label' => ts('Lifetime Donations (USD)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'select_clause' => "SUM(IF(c.financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total",
      ],
      'endowment_lifetime_usd_total' => [
        'name' => 'endowment_lifetime_usd_total',
        'column_name' => 'endowment_lifetime_usd_total',
        'label' => ts('Endowment Lifetime Donations (USD)'),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'default_value' => 0,
        'is_view' => 1,
        'select_clause' => "SUM(IF(c.financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_lifetime_usd_total",
      ],
      'last_donation_date' => [
        'name' => 'last_donation_date',
        'column_name' => 'last_donation_date',
        'label' => ts('Last donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'select_clause' => "MAX(IF(c.financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date",
      ],
      'endowment_last_donation_date' => [
        'name' => 'endowment_last_donation_date',
        'column_name' => 'endowment_last_donation_date',
        'label' => ts('Endowment Last donation date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_active' => 1,
        'is_searchable' => 1,
        'is_search_range' => 1,
        'is_view' => 1,
        'date_format' => 'M d, yy',
        'time_format' => 2,
        'select_clause' => "MAX(IF(c.financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_last_donation_date",
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
      $weight = $year > 2018 ? ($year - 2000) : (2019 - $year);
      // Add financial year fields (5 years worth).
      $this->calculatedFields["total_{$year}_{$nextYear}"] = [
        'name' => "total_{$year}_{$nextYear}",
        'column_name' => "total_{$year}_{$nextYear}",
        'label' => ts("FY {$year}-{$nextYear} total"),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'default_value' => 0,
        'is_active' => 1,
        'is_required' => 0,
        'is_searchable' => ($year > 2019),
        'is_view' => 1,
        'weight' => $weight,
        'is_search_range' => 1,
        'select_clause' => "SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}",
      ];
      // Add calendar year fields.
      if ($year >= self::WMF_MIN_CALENDER_YEAR) {
        $this->calculatedFields["total_{$year}"] = [
          'name' => "total_{$year}",
          'column_name' => "total_{$year}",
          'label' => ts("CY {$year} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => ($year > 2019),
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "SUM(COALESCE(IF(c.financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as total_{$year}",
        ];
      }
      if ($year >= 2018) {
        // Financial year totals for endowment (5 years), but we only started in 2018.
        $this->calculatedFields["endowment_total_{$year}_{$nextYear}"] = array_merge(
          $this->calculatedFields["total_{$year}_{$nextYear}"], [
            'name' => "endowment_total_{$year}_{$nextYear}",
            'column_name' => "endowment_total_{$year}_{$nextYear}",
            'label' => 'Endowment ' . ts("FY {$year}-{$nextYear} total"),
            'select_clause' => "SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}_{$nextYear}",
          ]
        );
        // Endowment field total from 2018
        if ($year >= self::WMF_MIN_CALENDER_YEAR) {
          $this->calculatedFields["endowment_total_{$year}"] = array_merge(
            $this->calculatedFields["total_{$year}"], [
              'name' => "endowment_total_{$year}",
              'column_name' => "endowment_total_{$year}",
              'label' => 'Endowment ' . ts("CY {$year} total"),
              'select_clause' => "SUM(COALESCE(IF(c.financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}",
            ]
          );
        }
        // Financial year totals for all funds (5 years). But we only started in 2018
        $this->calculatedFields["all_funds_total_{$year}_{$nextYear}"] = array_merge(
          $this->calculatedFields["total_{$year}_{$nextYear}"], [
            'name' => "all_funds_total_{$year}_{$nextYear}",
            'column_name' => "all_funds_total_{$year}_{$nextYear}",
            'label' => 'All Funds ' . ts("FY {$year}-{$nextYear} total"),
            'select_clause' => "SUM(COALESCE(IF(receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as all_funds_total_{$year}_{$nextYear}",
          ]
        );
      }
      if ($nextYear >= self::WMF_MIN_CALENDER_YEAR) {
        // Change fields, year ending in this year onwards, co-incident with our calendar years.
        $this->calculatedFields["all_funds_change_{$year}_{$nextYear}"] = [
          'name' => "all_funds_change_{$year}_{$nextYear}",
          'column_name' => "all_funds_change_{$year}_{$nextYear}",
          'label' => ts("All Funds Change {$year}-{$nextYear} total"),
          'data_type' => 'Money',
          'html_type' => 'Text',
          'default_value' => 0,
          'is_active' => 1,
          'is_required' => 0,
          'is_searchable' => ($year > 2019),
          'is_view' => 1,
          'weight' => $weight,
          'is_search_range' => 1,
          'select_clause' => "SUM(COALESCE(IF(receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
            - SUM(COALESCE(IF(receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
             as all_funds_change_{$year}_{$nextYear}",
        ];

        $this->calculatedFields["endowment_change_{$year}_{$nextYear}"] = [
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
        $this->calculatedFields["change_{$year}_{$nextYear}"] = [
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

    return $this->calculatedFields;
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
   * @return string
   */
  protected function getUpdateWMFDonorSql(): string {
    return '
      INSERT INTO wmf_donor (
        entity_id, ' . implode(",\n", array_keys($this->getCalculatedFields())) . '
      )'
      . $this->getSelectSQL() . '
     ON DUPLICATE KEY UPDATE
    ' . implode(",\n", $this->getUpdateClauses()) . ";";
  }

  /**
   * Get the string to select the wmf donor data.
   *
   * @return string
   */
  public function getSelectSQL(): string {
    $endowmentFinancialType = $this->getEndowmentFinancialType();
    return 'SELECT
      ' . ($this->isTriggerContext() ? ' NEW.contact_id as entity_id , ' : ' totals.contact_id as entity_id , ')
      . '# to honour FULL_GROUP_BY mysql mode we need an aggregate command for each
 # field - even though we know we just want `the value from the subquery`
 # MAX is a safe wrapper for that
 # https://www.percona.com/blog/2019/05/13/solve-query-failures-regarding-only_full_group_by-sql-mode/
 '
      . implode(', ', $this->getSelectsAggregate()) . "

    FROM (
      SELECT\n " . (!$this->isTriggerContext() ? ' c.contact_id,' : '')
      . implode(', ', $this->getTotalsFieldSelects()) . "
      FROM civicrm_contribution c
      USE INDEX(FK_civicrm_contribution_contact_id)
        LEFT JOIN civicrm_contribution_recur annual_recur
           ON annual_recur.id = c.contribution_recur_id
           AND annual_recur.frequency_unit = 'year'
           -- contribution_status_id != cancelled?
      WHERE " . ($this->isTriggerContext() ? ' c.contact_id = NEW.contact_id ' : $this->getWhereClause()) . "
        AND c.contribution_status_id = 1
        AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)"
      . (!$this->isTriggerContext() ? ' GROUP BY contact_id ' : '') . "
    ) as totals" .

      (!$this->isIncludeTable('latest') ? '' : "

  LEFT JOIN civicrm_contribution latest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON latest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id') . "
    AND latest.receive_date = totals.last_donation_date
    AND latest.contribution_status_id = 1
    AND latest.total_amount > 0
    AND (latest.trxn_id NOT LIKE 'RFD %' OR latest.trxn_id IS NULL)
    AND latest.financial_type_id <> $endowmentFinancialType
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = latest.id
") .

      (!$this->isIncludeTable('earliest') ? '' : "
  LEFT JOIN civicrm_contribution earliest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON earliest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id') . "
    AND earliest.receive_date = totals.first_donation_date
    AND earliest.contribution_status_id = 1
    AND earliest.total_amount > 0
    AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)")

      . (!$this->isIncludeTable('largest') ? '' : "

  LEFT JOIN civicrm_contribution largest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON largest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id ' : ' totals.contact_id ') . "
    AND largest.total_amount = totals.largest_donation
    AND largest.contribution_status_id = 1
    AND largest.total_amount > 0
    AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)") .

      " GROUP BY " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id');
  }

  /**
   * Run a back fill on WMF donor data.
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
    \CRM_Core_DAO::executeQuery($this->getUpdateWMFDonorSql());
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
   * Get the select clause for the donor segment status.
   *
   * Currently a placeholder.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getSegmentStatusSelect(): string {
    if (!$this->statusSelectSQL) {
      $options = $this->getDonorStatusOptions();
      $this->statusSelectSQL = "\nCASE";
      foreach ($options as $option) {
        if (!empty($option['sql_select'])) {
          $this->statusSelectSQL .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->statusSelectSQL .= '
       ELSE 1000
       END  as donor_status_id';
    }
    return $this->statusSelectSQL;
  }

  /**
   * Get the select clause for the donor segment.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getSegmentSelect(): string {
    if (!$this->segmentSelectSQL) {
      $options = $this->getDonorSegmentOptions();
      $this->segmentSelectSQL = ' CASE ';
      foreach ($options as $option) {
        if (!empty($option['sql_select'])) {
          $this->segmentSelectSQL .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->segmentSelectSQL .= '
       ELSE 1000
       END  as donor_segment_id';
    }
    return $this->segmentSelectSQL;
  }

  /**
   * Get explanations of the segment options.
   *
   * This is primary for fr-tech brain injury prevention during QA.
   * It's not clear the longer term role of this function.
   *
   * @throws \CRM_Core_Exception
   */
  public function getSegmentOptionDetails($value): string {
    foreach ($this->getDonorSegmentOptions() as $segmentOption) {
      if ($segmentOption['value'] === $value) {
        return $segmentOption['static_description'] . ' ' . $segmentOption['description'];
      }
    }
    return '';
  }

  /**
   * Get the options for the donor status field.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/1qM36MeKWyOENl-iR5umuLph5HLHG6W_6c46xJUdE3QY/edit
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getDonorStatusOptions(): array {
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
   * Get the options for the donor segment field.
   *
   * Ref
   * https://docs.google.com/spreadsheets/d/1qM36MeKWyOENl-iR5umuLph5HLHG6W_6c46xJUdE3QY/edit
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getDonorSegmentOptions(): array {
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
  public function convertDateOffSetToSQL(string $textDateOffset): string {
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
    return "COALESCE(IF($additionalCriteria receive_date
      BETWEEN (" . $this->convertDateOffsetToSQL($range['from']) . ") AND (" . $this->convertDateOffsetToSQL($range['to']) . ')
      , total_amount, 0), 0)';
  }

  /**
   * @param $range
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getTextClause($range): string {
    $comparison = isset($range['total']) ? 'at least ' : ' less than ';
    $amount = $range['total'] ?? $range['max_total'];
    $textClause = $comparison . \Civi::format()->money($amount, 'USD', 'en-US') .
      ' between ' . date('Y-m-d H:i:s', strtotime($range['from'])) . ' and ' .
      date('Y-m-d H:i:s', strtotime($range['to']));
    if (!empty($range['additional_criteria'])) {
      // Currently this is the only additional criteria defined so
      // let's cut a corner.
      $textClause .= ' AND donation is recurring';
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
      $clauses = implode(' OR ', $rangeClauses);
      $dynamicDescription = implode(" OR \n", $textClauses);
    }
    if (!empty($detail['criteria']['multiple_range'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['multiple_range'] as $range) {
        $rangeClauses[] = 'SUM(' . $this->getRangeClause($range) . ')' . $this->getValueComparisonClause($range);
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode(' AND ', $rangeClauses);
      $dynamicDescription = implode(" AND \n", $textClauses);
    }
    if (!empty($detail['criteria']['first_donation'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['first_donation'] as $range) {
        $rangeClauses[] = 'MIN(' . $this->getRangeClause($range) . ')' . $this->getValueComparisonClause($range);
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode(' OR ', $rangeClauses);
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
         --  {$detail['label']}  {$detail['static_description']}
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
    if (isset($criteria['max_total'])) {
      return ' < ' . $criteria['max_total'];
    }
    // This would only be hit during development so is just for clarity.
    throw new \CRM_Core_Exception('No total specified');
  }

}

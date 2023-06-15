<?php
// Class to hold wmf functionality that alters permissions.

namespace Civi\WMFHooks;

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\Generic\Result;
use CRM_Core_PseudoConstant;

class CalculatedData extends TriggerHook {

  protected const WMF_MIN_ROLLUP_YEAR = 2006;
  protected const WMF_MAX_ROLLUP_YEAR = 2023;

  /**
   * Is this class is being called in trigger context.
   *
   * The normal context is 'trigger' which will generate sql triggers.
   * However, sometimes we want to do an sql_update to backfill
   * missing wmf_donor data - in which case we want to get
   * the same sql but to refer to the existing contact id rather
   * than the NEW or OLD contact ids - which are key words that
   * are only meaningful in the context of triggers.
   */
  protected $triggerContext = TRUE;

  /**
   * Should the donor segment be included even if the field is not there.
   *
   * This is a transitional mechanism to allow us to add segment data in advance
   * of prod having the field (which will be added late June 2023).
   * In WMFDonor.get mode we are retrieving
   * what WOULD be calculated - so we force it to be included via this
   * flag.
   *
   * @var bool
   */
  public $isForceSegment = FALSE;

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
   * @param bool $isForceSegment
   *
   * @return \Civi\WMFHooks\CalculatedData
   */
  public function setIsForceSegment(bool $isForceSegment): self {
    $this->isForceSegment = $isForceSegment;
    return $this;
  }

  /**
   * @param bool $triggerContext
   *
   * @return \Civi\WMFHooks\CalculatedData
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
    return CustomGroup::get( FALSE )
      ->setSelect( [ 'id' ] )
      ->addWhere( 'name', '=', 'wmf_donor' )
      ->execute()
      ->first()['id'];
  }

  /**
   * Get (basic) data about the wmf donor fields.
   *
   * @throws \API_Exception
   *
   * @return \Civi\Api4\Generic\Result
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
   * On our anonymous contact it was perhaps 100 ms but we don't have many
   * contact with thousands of donations.)
   *
   * The wmf_contribution_extra record is saved after the contribution is
   * inserted.
   *
   * so we need to potentially update the fields from that record at that points,
   * with a separate trigger.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \API_Exception
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

      $matchingGiftDonors = civicrm_api3('Contact', 'get', ['nick_name' => ['IN' => ['Microsoft', 'Google', 'Apple']]])['values'];
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
   * @throws \API_Exception
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
        'select_clause' => "MAX(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation",
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
        'select_clause' => "MAX(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_largest_donation",
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
        'select_clause' => "SUM(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total",
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
        'select_clause' => "SUM(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_lifetime_usd_total",
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
        'select_clause' => "MAX(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date",
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
        'select_clause' => "MAX(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_last_donation_date",
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
        'select_clause' => "MIN(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS first_donation_date",
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
        'select_clause' => "MIN(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_first_donation_date",
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
        'select_clause' => "COUNT(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations",
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
        'select_clause' => "COUNT(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_number_donations",
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
      $weight = $year > 2018 ? ($year - 2000) : (2019 - $year);
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
        'select_clause' => "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}",
      ];
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
        'select_clause' => "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as total_{$year}",
      ];
      if ($year >= 2017) {
        if ($year >= 2018) {
          $this->calculatedFields["endowment_total_{$year}_{$nextYear}"] = array_merge(
            $this->calculatedFields["total_{$year}_{$nextYear}"], [
            'name' => "endowment_total_{$year}_{$nextYear}",
            'column_name' => "endowment_total_{$year}_{$nextYear}",
            'label' => 'Endowment ' . ts("FY {$year}-{$nextYear} total"),
            'select_clause' => "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}",
          ]);
          $this->calculatedFields["endowment_total_{$year}"] = array_merge(
            $this->calculatedFields["total_{$year}"], [
              'name' => "endowment_total_{$year}",
              'column_name' => "endowment_total_{$year}",
              'label' => 'Endowment ' . ts("CY {$year} total"),
              'select_clause' => "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}_{$nextYear}",
            ]);
          $this->calculatedFields["all_funds_total_{$year}_{$nextYear}"] = array_merge(
            $this->calculatedFields["total_{$year}_{$nextYear}"], [
              'name' => "all_funds_total_{$year}_{$nextYear}", 'column_name' => "all_funds_total_{$year}_{$nextYear}", 'label' => 'All Funds ' . ts("FY {$year}-{$nextYear} total"),
              'select_clause' => "SUM(COALESCE(IF(receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as all_funds_total_{$year}_{$nextYear}",
            ]);
        }
        if ($year > 2017) {
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
            'select_clause' => "
              SUM(COALESCE(IF(receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
              - SUM(COALESCE(IF(receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
               as all_funds_change_{$year}_{$nextYear}",
          ];
        }
        if ($year > 2019) {
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
               SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
              - SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
               as endowment_change_{$year}_{$nextYear}",
          ];
        }
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
            SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
            - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
            as change_{$year}_{$nextYear}",
        ];
      }
    }
    if (!$this->isSegmentReady()) {
      unset($this->calculatedFields['donor_segment_id'], $this->calculatedFields['donor_status_id']);
    }

    return $this->calculatedFields;
  }

  /**
   * Is the world ready for donor segments.
   *
   * More specifically - is the database ready for them. We don't want to inadvertently
   * add triggers for these fields to production before we have added the fields.
   *
   * We don't want to accidentally add the fields either - but we would have to actively
   * run WMFConfig::syncCustomFields(FALSE)->execute(); to do that - so we can probably avoid that
   * for 2 weeks (really we probably wont' run triggers either).
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  private function isSegmentReady() : bool {
    if (!empty(\Civi::$statics['is_install_mode']) || \CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_custom_field WHERE name = "donor_segment_id"')) {
      return TRUE;
    }
    return $this->isForceSegment;
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
        entity_id, ' . implode(', ', array_keys($this->getCalculatedFields())) . '
      )'
    . $this->getSelectSQL()  . '
     ON DUPLICATE KEY UPDATE
    ' . implode(', ', $this->getUpdateClauses()) . ";";
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
      SELECT\n " . (!$this->isTriggerContext() ? ' c.contact_id,': '')
      . implode(', ', $this->getTotalsFieldSelects()) . "
      FROM civicrm_contribution c
      USE INDEX(FK_civicrm_contribution_contact_id)
      WHERE " . ($this->isTriggerContext() ? ' contact_id = NEW.contact_id ' : $this->getWhereClause()) . "
        AND contribution_status_id = 1
        AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)"
      . (!$this->isTriggerContext() ? ' GROUP BY contact_id ': '') ."
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

  .  (!$this->isIncludeTable('largest') ? '' : "

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
      $this->statusSelectSQL  = "\nCASE";
      foreach ($options as $option) {
        if (!empty($option['sql_select'])) {
          $this->statusSelectSQL .= "\n" . $option['sql_select'] . ' THEN ' . $option['value'] . "\n";
        }
      }
      $this->statusSelectSQL .= '
       ELSE 100
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
      $this->segmentSelectSQL  = ' CASE ';
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
    $details = [
      10 => [
        'label' => 'New',
        'static_description' => '',
        'value' => 10,
        'name' => 'new',
        'criteria' => [
          'first_donation' => [
            ['from' => '6 months ago', 'to' => 'now', 'total' => 0.01],
          ],
        ],
      ],
      20 => [
        'label' => 'Consecutive',
        'static_description' => 'gave in last 12 months and 12 months prior',
        'value' => 20,
        'name' => 'consecutive',
        'criteria' => [
          // multiple ranges are AND rather than OR
          'multiple_range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 0.01],
            [
              'from' => '24 months ago',
              'to' => '12 months ago',
              'total' => 0.01,
            ],
          ],
        ],
      ],
      30 => [
        'name' => 'active',
        'label' => 'Active',
        'value' => 30,
        'static_description' => 'gave within the last 12 months, or 3 months if recurring',
        'criteria' => [
          'range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 0.01],
            [
              'from' => '3 months ago',
              'to' => 'now',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL'],
            ],
          ],
        ],
      ],
      40 => [
        'label' => 'Recent Lapsed',
        'static_description' => '4-6 months ago recurring',
        'value' => 40,
        'name' => 'recent_lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => '4 months ago',
              'to' => '6 months ago',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL'],
            ],
          ],
        ],
      ],
      50 => [
        'label' => 'Lapsed',
        'static_description' => 'last gave 12-36 months ago, or 7-36 months, if recurring',
        'value' => 50,
        'name' => 'lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => '36 months ago',
              'to' => '12 months ago',
              'total' => 0.01,
            ],
            [
              'from' => '36 months ago',
              'to' => '7 months ago',
              'total' => 0.01,
              'additional_criteria' => ['contribution_recur_id IS NOT NULL'],
            ],
          ],
        ],
      ],
      60 => [
        'label' => 'Deep Lapsed',
        'static_description' => 'last gave 36-60 months ago',
        'value' => 60,
        'name' => 'deep_lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => '60 months ago',
              'to' => '36 months ago',
              'total' => 0.01,
            ],
          ],
        ],
      ],
      70 => [
        'label' => 'Ultra lapsed',
        'static_description' => 'gave prior to 60 months ago',
        'value' => 70,
        'name' => 'ultra_lapsed',
        'criteria' => [
          'range' => [
            [
              'from' => '200 months ago',
              'to' => '60 months ago',
              'total' => 0.01,
            ],
          ],
        ],
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
   * https://docs.google.com/spreadsheets/d/1qM36MeKWyOENl-iR5umuLph5HLHG6W_6c46xJUdE3QY/edit
   *
   * @return array[]
   * @throws \CRM_Core_Exception
   */
  public function getDonorSegmentOptions(): array {
    $details = [
      100 => [
        'label' => 'Major Donor',
        'value' => 100,
        // Use for triggers instead of the dynamic description, which will date.
        'static_description' => 'has given 10,000+ in one of the past 3 12 month periods',
        'name' => 'major_donor',
        'criteria' => [
          'range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 10000],
            ['from' => '24 months ago', 'to' => '12 month ago', 'total' => 10000],
            ['from' => '36 months ago', 'to' => '24 months ago', 'total' => 10000],
          ],
        ],
      ],
      200 => [
        'label' => 'Mid Tier',
        'value' => 200,
        'static_description' => 'has given 1,000+ in one of the past 5 12 month periods.',
        'name' => 'mid_tier',
        'criteria' => [
          'range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 1000],
            ['from' => '24 months ago', 'to' => '12 month ago', 'total' => 1000],
            ['from' => '36 months ago', 'to' => '24 months ago', 'total' => 1000],
            ['from' => '48 months ago', 'to' => '36 months ago', 'total' => 1000],
            ['from' => '60 months ago', 'to' => '48 months ago', 'total' => 1000],
          ],
        ],
      ],
      300 => [
        'label' => 'Mid-Value Prospect',
        'value' => 300,
        'static_description' => 'has given 250+ in one of the past 3 12 month periods',
        'name' => 'mid_value',
        'criteria' => [
          'range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 250],
            ['from' => '24 months ago', 'to' => '12 month ago', 'total' => 250],
            ['from' => '36 months ago', 'to' => '24 months ago', 'total' => 250],
          ],
        ],
      ],
      400 => [
        'label' => 'Recurring donor',
        'value' => 400,
        'static_description' => 'has made a recurring donation in last 36 months',
        'name' => 'recurring',
        'criteria' => [
          'range' => [
            ['from' => '36 months ago', 'to' => 'now', 'total' => 0.01, 'additional_criteria' => ['contribution_recur_id IS NOT NULL']],
          ],
        ],
      ],
      500 => [
        'label' => 'Grassroots Plus Donor',
        'value' => 500,
        'static_description' => 'has given 50+ in one of the past 3 12 month periods',
        'name' => 'grass_roots_plus',
        'criteria' => [
          'range' => [
            ['from' => '12 months ago', 'to' => 'now', 'total' => 50],
            ['from' => '24 months ago', 'to' => '12 month ago', 'total' => 50],
            ['from' => '36 months ago', 'to' => '24 months ago', 'total' => 50],
          ],
        ],
      ],
      600 => [
        'label' => 'Grassroots Donor',
        'value' => 600,
        'static_description' => 'has given in the last 36 months',
        'name' => 'grass_roots',
        'criteria' => [
          'range' => [
            ['from' => '36 months ago', 'to' => 'now', 'total' => .01],
          ],
        ],
      ],
      700 => [
        'label' => 'Deep lapsed Donor',
        'value' => 700,
        'static_description' => 'has given between 36 & 60 months ago.',
        'name' => 'deep_lapsed',
        'criteria' => [
          'range' => [
            ['from' => '60 months ago', 'to' => '38 months ago', 'total' => .01],
          ],
        ],
      ],
      800 => [
        'label' => 'Ultra lapsed Donor',
        'value' => 800,
        'static_description' => 'Has given more than 60 months ago',
        'name' => 'ultra_lapsed',
        'criteria' => [
          'range' => [
            ['from' => '200 months ago', 'to' => '60 months ago', 'total' => .01],
          ],
        ],
      ],
      900 => [
        'label' => 'All other Donors',
        'value' => 900,
        'static_description' => 'How could this be reachable?',
        'name' => 'other_donor',
        'criteria' => [
          'range' => [
            ['from' => '200 months ago', 'to' => 'now', 'total' => .01],
          ],
        ],
      ],
      1000 => [
        'label' => 'Non Donors',
        'value' => 1000,
        'static_description' => 'this can not be calculated with the others. We will have to populate once & then ?',
        'name' => 'non_donor',
        'description' => 'never donated'
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
    if ($textDateOffset === 'now') {
      return 'NOW()';
    }
    $split = explode(' ', $textDateOffset);
    $offset = $split[0];
    $interval = strtoupper($split[1]);
    if ($interval === 'MONTHS') {
      $interval = 'MONTH';
    }
    return "NOW() - INTERVAL $offset $interval";
  }

  /**
   * Get the crazy insane clause for this range.
   *
   * @param array $range
   *
   * @return string
   */
  protected function getRangeClause(array $range): string {
    $additionalCriteria = empty($range['additional_criteria']) ? '' : (implode(', ', $range['additional_criteria']) . ' AND ');
    return "COALESCE(IF($additionalCriteria receive_date
      BETWEEN (" . $this->convertDateOffsetToSQL($range['from']) . ") AND (" . $this->convertDateOffsetToSQL($range['to']) . ')
      , total_amount, 0), 0)' . ($range['total'] === 0 ? ' > ' : ' >= ') . $range['total'];
  }

  /**
   * @param $range
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getTextClause($range): string {
    $textClause = 'at least ' . \Civi::format()
        ->money($range['total']) . ' between ' . date('Y-m-d H:i:s', strtotime($range['from'])) . ' and ' . date('Y-m-d H:i:s', strtotime($range['to']));
    if (!empty($range['additional_criteria'])) {
      // Currently this is the only additional criteria defined so
      // let's cut a corner.
      $textClause .= ' AND is recurring';
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
    // For not we are safe that only one type of range exists - ie standard
    // 'or range', multiple_range (and) or first_donation. If that changes the below
    // will need a re-write, if it doesn't get re-written first .. in another pass.
    if (!empty($detail['criteria']['range'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['range'] as $range) {
        $rangeClauses[] = 'SUM(' . $this->getRangeClause($range) . ')';
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode(' OR ', $rangeClauses);
      $dynamicDescription = implode(" OR \n", $textClauses);
    }
    if (!empty($detail['criteria']['multiple_range'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['multiple_range'] as $range) {
        $rangeClauses[] = 'SUM(' . $this->getRangeClause($range) . ')';
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode(' AND ', $rangeClauses);
      $dynamicDescription = implode(" AND \n", $textClauses);
    }
    if (!empty($detail['criteria']['first_donation'])) {
      $rangeClauses = [];
      $textClauses = [];
      foreach ($detail['criteria']['first_donation'] as $range) {
        $rangeClauses[] = 'MIN(' . $this->getRangeClause($range) . ')';
        $textClauses[] = $this->getTextClause($range);
      }
      $clauses = implode(' OR ', $rangeClauses);
      $dynamicDescription = implode(" OR \n", $textClauses);
    }
    $sqlSelect = "
         WHEN (
         --  {$detail['label']}  {$detail['static_description']}
         $clauses

        )";
    $detail['sql_select'] = $sqlSelect;
    $detail['description'] = $detail['static_description'] . " - ie \n" . $dynamicDescription;
  }

}

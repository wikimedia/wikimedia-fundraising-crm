<?php
// Class to hold wmf functionality that alters permissions.

namespace Civi\WMFHooks;

use Civi\Api4\CustomGroup;
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
   * Where clause to restrict contacts/ contributions to include.
   *
   * This clause is used when doing an update out of trigger context
   * to restrict the contacts being updated.
   *
   * @var string
   */
  protected $whereClause;

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
   * Get fields for wmf_donor custom group.
   *
   * This is the group with the custom fields for calculated donor data.
   *
   * @return array
   */
  public function getWMFDonorFields(): array {
    $fields = [
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
      ],
    ];

    for ($year = self::WMF_MIN_ROLLUP_YEAR; $year <= self::WMF_MAX_ROLLUP_YEAR; $year++) {
      $nextYear = $year + 1;
      $weight = $year > 2018 ? ($year - 2000) : (2019 - $year);
      $fields["total_{$year}_{$nextYear}"] = [
        'name' => "total_{$year}_{$nextYear}",
        'column_name' => "total_{$year}_{$nextYear}",
        'label' => ts("FY {$year}-{$nextYear} total"),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'default_value' => 0,
        'is_active' => 1,
        'is_required' => 0,
        'is_searchable' => ($year > 2015),
        'is_view' => 1,
        'weight' => $weight,
        'is_search_range' => 1,
      ];
      $fields["total_{$year}"] = [
        'name' => "total_{$year}",
        'column_name' => "total_{$year}",
        'label' => ts("CY {$year} total"),
        'data_type' => 'Money',
        'html_type' => 'Text',
        'default_value' => 0,
        'is_active' => 1,
        'is_required' => 0,
        'is_searchable' => ($year > 2015),
        'is_view' => 1,
        'weight' => $weight,
        'is_search_range' => 1,
      ];
      if ($year >= 2017) {
        if ($year >= 2018) {
          $fields["endowment_total_{$year}"] = array_merge(
            $fields["total_{$year}"],
            ['name' => "endowment_total_{$year}", 'column_name' => "endowment_total_{$year}", 'label' => 'Endowment ' . ts("CY {$year} total")]
          );
          $fields["endowment_total_{$year}_{$nextYear}"] = array_merge(
            $fields["total_{$year}_{$nextYear}"],
            ['name' => "endowment_total_{$year}_{$nextYear}", 'column_name' => "endowment_total_{$year}_{$nextYear}", 'label' => 'Endowment ' . ts("FY {$year}-{$nextYear} total")]
          );
          $fields["all_funds_total_{$year}_{$nextYear}"] = array_merge(
            $fields["total_{$year}_{$nextYear}"],
            ['name' => "all_funds_total_{$year}_{$nextYear}", 'column_name' => "all_funds_total_{$year}_{$nextYear}", 'label' => 'All Funds ' . ts("FY {$year}-{$nextYear} total")]
          );
        }
        $fields["change_{$year}_{$nextYear}"] = [
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
        ];
        if ($year > 2019) {
          $fields["endowment_change_{$year}_{$nextYear}"] = [
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
          ];
        }
        if ($year > 2017) {
          $fields["all_funds_change_{$year}_{$nextYear}"] = [
            'name' => "all_funds_change_{$year}_{$nextYear}",
            'column_name' => "all_funds_change_{$year}_{$nextYear}",
            'label' => ts("All Funds Change {$year}-{$nextYear} total"),
            'data_type' => 'Money',
            'html_type' => 'Text',
            'default_value' => 0,
            'is_active' => 1,
            'is_required' => 0,
            'is_searchable' => 1,
            'is_view' => 1,
            'weight' => $weight,
            'is_search_range' => 1,
          ];
        }
      }
    }

    return $fields;
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
    $fields = $aggregateFieldStrings = [];
    $endowmentFinancialType = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    for ($year = self::WMF_MIN_ROLLUP_YEAR; $year <= self::WMF_MAX_ROLLUP_YEAR; $year++) {
      $nextYear = $year + 1;
      $fields[] = "total_{$year}_{$nextYear}";
      $aggregateFieldStrings[] = "MAX(total_{$year}_{$nextYear}) as total_{$year}_{$nextYear}";
      $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as total_{$year}_{$nextYear}";
      $updates[] = "total_{$year}_{$nextYear} = VALUES(total_{$year}_{$nextYear})";

      $fields[] = "total_{$year}";
      $aggregateFieldStrings[] = "MAX(total_{$year}) as total_{$year}";
      $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as total_{$year}";
      $updates[] = "total_{$year} = VALUES(total_{$year})";

      if ($year >= 2017) {
        if ($year >= 2018) {
          $fields[] = "endowment_total_{$year}_{$nextYear}";
          $aggregateFieldStrings[] = "MAX(endowment_total_{$year}_{$nextYear}) as endowment_total_{$year}_{$nextYear}";
          $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}_{$nextYear}";
          $updates[] = "endowment_total_{$year}_{$nextYear} = VALUES(endowment_total_{$year}_{$nextYear})";

          $fields[] = "endowment_total_{$year}";
          $aggregateFieldStrings[] = "MAX(endowment_total_{$year}) as endowment_total_{$year}";
          $fieldSelects[] = "SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0)) as endowment_total_{$year}";
          $updates[] = "endowment_total_{$year} = VALUES(endowment_total_{$year})";

          $fields[] = "all_funds_total_{$year}_{$nextYear}";
          $aggregateFieldStrings[] = "MAX(all_funds_total_{$year}_{$nextYear}) as all_funds_total_{$year}_{$nextYear}";
          $fieldSelects[] = "SUM(COALESCE(IF(receive_date BETWEEN '{$year}-07-01' AND '{$nextYear}-06-30 23:59:59', c.total_amount, 0),0)) as all_funds_total_{$year}_{$nextYear}";
          $updates[] = "all_funds_total_{$year}_{$nextYear} = VALUES(all_funds_total_{$year}_{$nextYear})";

          $fields[] = "all_funds_change_{$year}_{$nextYear}";
          $aggregateFieldStrings[] = "MAX(change_{$year}_{$nextYear}) as change_{$year}_{$nextYear}";
          $fieldSelects[] = "
          SUM(COALESCE(IF(receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
           as all_funds_change_{$year}_{$nextYear}";
          $updates[] = "all_funds_change_{$year}_{$nextYear} = VALUES(all_funds_change_{$year}_{$nextYear})";

          if ($year >= 2020) {
            $fields[] = "endowment_change_{$year}_{$nextYear}";
            $aggregateFieldStrings[] = "MAX(endowment_change_{$year}_{$nextYear}) as endowment_change_{$year}_{$nextYear}";
            $fieldSelects[] = "
          SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id = $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
           as endowment_change_{$year}_{$nextYear}";
            $updates[] = "endowment_change_{$year}_{$nextYear} = VALUES(endowment_change_{$year}_{$nextYear})";

          }
        }

        $fields[] = "change_{$year}_{$nextYear}";
        $aggregateFieldStrings[] = "MAX(change_{$year}_{$nextYear}) as change_{$year}_{$nextYear}";
        $fieldSelects[] = "
          SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$nextYear}-01-01' AND '{$nextYear}-12-31 23:59:59', c.total_amount, 0),0))
          - SUM(COALESCE(IF(financial_type_id <> $endowmentFinancialType AND receive_date BETWEEN '{$year}-01-01' AND '{$year}-12-31 23:59:59', c.total_amount, 0),0))
           as change_{$year}_{$nextYear}";
        $updates[] = "change_{$year}_{$nextYear} = VALUES(change_{$year}_{$nextYear})";
      }
    }

    return '
    INSERT INTO wmf_donor (
      entity_id, last_donation_currency, last_donation_amount, last_donation_usd,
      first_donation_usd, date_of_largest_donation,
      largest_donation, endowment_largest_donation, all_funds_largest_donation,
      lifetime_including_endowment,
      lifetime_usd_total, endowment_lifetime_usd_total,
      last_donation_date, endowment_last_donation_date, all_funds_last_donation_date,
      first_donation_date,
      endowment_first_donation_date,
      all_funds_first_donation_date, number_donations,
      endowment_number_donations, all_funds_number_donations,
      ' . implode(', ', $fields) . '
    )

    SELECT
      ' . ($this->isTriggerContext() ? ' NEW.contact_id as entity_id ' : ' totals.contact_id as entity_id ') . ',
       # to honour FULL_GROUP_BY mysql mode we need an aggregate command for each
      # field - even though we know we just want `the value from the subquery`
      # MAX is a safe wrapper for that
      # https://www.percona.com/blog/2019/05/13/solve-query-failures-regarding-only_full_group_by-sql-mode/
      MAX(COALESCE(x.original_currency, latest.currency)) as last_donation_currency,
      MAX(COALESCE(x.original_amount, latest.total_amount, 0)) as last_donation_amount,
      MAX(COALESCE(latest.total_amount, 0)) as last_donation_usd,
      MAX(COALESCE(earliest.total_amount, 0)) as first_donation_usd,
      MAX(largest.receive_date) as date_of_largest_donation,
      MAX(largest_donation) as largest_donation,
      MAX(endowment_largest_donation) as endowment_largest_donation,
      MAX(all_funds_largest_donation) as all_funds_largest_donation,
      MAX(lifetime_including_endowment) as lifetime_including_endowment,
      MAX(lifetime_usd_total) as lifetime_usd_total,
      MAX(endowment_lifetime_usd_total) as endowment_lifetime_usd_total,
      MAX(last_donation_date) as last_donation_date,
      MAX(endowment_last_donation_date) as endowment_last_donation_date,
      MAX(all_funds_last_donation_date) as all_funds_last_donation_date,
      MIN(first_donation_date) as first_donation_date,
      MIN(endowment_first_donation_date) as endowment_first_donation_date,
      MIN(all_funds_first_donation_date) as all_funds_first_donation_date,
      MAX(number_donations) as number_donations,
      MAX(endowment_number_donations) as endowment_number_donations,
      MAX(all_funds_number_donations) as all_funds_number_donations,
      ' . implode(',', $aggregateFieldStrings) . "

    FROM (
      SELECT" . (!$this->isTriggerContext() ? ' c.contact_id,': '') ."
        MAX(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS largest_donation,
        MAX(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_largest_donation,
        MAX(COALESCE(total_amount, 0)) AS all_funds_largest_donation,
        SUM(COALESCE(total_amount, 0)) AS lifetime_including_endowment,
        SUM(IF(financial_type_id <> $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS lifetime_usd_total,
        SUM(IF(financial_type_id = $endowmentFinancialType, COALESCE(total_amount, 0), 0)) AS endowment_lifetime_usd_total,
        MAX(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS last_donation_date,
        MAX(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_last_donation_date,
        MAX(IF(total_amount > 0, receive_date, NULL)) AS all_funds_last_donation_date,
        MIN(IF(financial_type_id <> $endowmentFinancialType AND total_amount, receive_date, NULL)) AS first_donation_date,
        MIN(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_first_donation_date,
        MIN(IF(total_amount > 0, receive_date, NULL)) AS all_funds_first_donation_date,
        COUNT(IF(financial_type_id <> $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS number_donations,
        COUNT(IF(financial_type_id = $endowmentFinancialType AND total_amount > 0, receive_date, NULL)) AS endowment_number_donations,
        COUNT(IF(total_amount > 0, receive_date, NULL)) AS all_funds_number_donations,
     " . implode(',', $fieldSelects) . "
      FROM civicrm_contribution c
      USE INDEX(FK_civicrm_contribution_contact_id)
      WHERE " . ($this->isTriggerContext() ? ' contact_id = NEW.contact_id ' : $this->getWhereClause()) . "
        AND contribution_status_id = 1
        AND (c.trxn_id NOT LIKE 'RFD %' OR c.trxn_id IS NULL)"
      . (!$this->isTriggerContext() ? ' GROUP BY contact_id ': '') ."
    ) as totals
  LEFT JOIN civicrm_contribution latest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON latest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id') . "
    AND latest.receive_date = totals.last_donation_date
    AND latest.contribution_status_id = 1
    AND latest.total_amount > 0
    AND (latest.trxn_id NOT LIKE 'RFD %' OR latest.trxn_id IS NULL)
    AND latest.financial_type_id <> $endowmentFinancialType
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = latest.id

  LEFT JOIN civicrm_contribution earliest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON earliest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id') . "
    AND earliest.receive_date = totals.first_donation_date
    AND earliest.contribution_status_id = 1
    AND earliest.total_amount > 0
    AND (earliest.trxn_id NOT LIKE 'RFD %' OR earliest.trxn_id IS NULL)
  LEFT JOIN civicrm_contribution largest
    USE INDEX(FK_civicrm_contribution_contact_id)
    ON largest.contact_id = " . ($this->isTriggerContext() ? ' NEW.contact_id ' : ' totals.contact_id ') . "
    AND largest.total_amount = totals.largest_donation
    AND largest.contribution_status_id = 1
    AND largest.total_amount > 0
    AND (largest.trxn_id NOT LIKE 'RFD %' OR largest.trxn_id IS NULL)
  GROUP BY " . ($this->isTriggerContext() ? ' NEW.contact_id' : ' totals.contact_id') . "

  ON DUPLICATE KEY UPDATE
    last_donation_currency = VALUES(last_donation_currency),
    last_donation_amount = VALUES(last_donation_amount),
    last_donation_usd = VALUES(last_donation_usd),
    first_donation_usd = VALUES(first_donation_usd),
    largest_donation = VALUES(largest_donation),
    date_of_largest_donation = VALUES(date_of_largest_donation),
    lifetime_usd_total = VALUES(lifetime_usd_total),
    last_donation_date = VALUES(last_donation_date),
    first_donation_date = VALUES(first_donation_date),
    number_donations = VALUES(number_donations),
    endowment_largest_donation = VALUES(endowment_largest_donation),
    all_funds_largest_donation = VALUES(all_funds_largest_donation),
    lifetime_including_endowment = VALUES(lifetime_including_endowment),
    endowment_lifetime_usd_total = VALUES(endowment_lifetime_usd_total),
    endowment_last_donation_date = VALUES(endowment_last_donation_date),
    all_funds_last_donation_date = VALUES(all_funds_last_donation_date),
    endowment_first_donation_date = VALUES(endowment_first_donation_date),
    all_funds_first_donation_date = VALUES(all_funds_first_donation_date),
    endowment_number_donations = VALUES(endowment_number_donations),
    all_funds_number_donations = VALUES(all_funds_number_donations),
    " . implode(',', $updates) . ";";
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

}

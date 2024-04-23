<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2012
 */
class CRM_ExtendedMailingStats_Form_Report_ExtendedMailingStats extends CRM_Report_Form {

  protected $_summary = NULL;

  protected $_customGroupExtends = array('Campaign');


  protected $_charts = array(
    '' => 'Tabular',
    'bar_3dChart' => 'Bar Chart',
  );

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->_columns = array();
    if (CRM_Campaign_BAO_Campaign::isComponentEnabled()) {
      $this->_columns['civicrm_mailing'] = array(
        'fields' => array(
          'mailing_id' => array(
            'title' => ts('Mailing ID'),
            'name' => 'id',
            'required' => TRUE,
          ),
          'mailing_campaign_id' => array(
            'title' => ts('Campaign'),
            'name' => 'campaign_id',
            'type' => CRM_Utils_Type::T_INT,
          ),
        ),
        'filters' => array(
          'mailing_campaign_id' => array(
            'title' => ts('Campaign'),
            'name' => 'campaign_id',
            'operatorType' => CRM_Report_Form::OP_MULTISELECT,
            'type' => CRM_Utils_Type::T_INT,
            'options' => CRM_Mailing_BAO_Mailing::buildOptions('campaign_id'),
          ),
        )
      );
    }

    $this->_columns = array_merge($this->_columns, $this->getCampaignColumns(), $this->getMailingStatColumns());

    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_mailing_name']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_is_completed']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_start']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_recipients']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_bounced']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_opened_total']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_opened_unique']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_unsubscribed']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_clicked_total']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_clicked_unique']['default'] = TRUE;
    $this->_columns['civicrm_mailing_stats']['fields']['mailing_stats_delivered']['default'] = TRUE;

    $this->_columns['civicrm_mailing_stats']['filters']['mailing_stats_start']['default'] = 'this.year';

    $this->_columns['civicrm_mailing_stats']['order_bys']['mailing_stats_start']['default_order'] = 'DESC';

    parent::__construct();
  }

  function mailing_select() {

    $data = array();

    $mailing = new CRM_Mailing_BAO_Mailing();
    $query = "SELECT name FROM civicrm_mailing WHERE sms_provider_id IS NULL";
    $mailing->query($query);

    while ($mailing->fetch()) {
      $data[mysql_real_escape_string($mailing->name)] = $mailing->name;
    }

    return $data;
  }

  function from() {

    $this->_from = "
      FROM civicrm_mailing_stats {$this->_aliases['civicrm_mailing_stats']}
      LEFT JOIN civicrm_mailing {$this->_aliases['civicrm_mailing']} ON
        {$this->_aliases['civicrm_mailing_stats']}.mailing_id = {$this->_aliases['civicrm_mailing']}.id
      ";
    if ($this->isTableSelected('civicrm_campaign')) {
      $this->_from .= "
        LEFT JOIN civicrm_campaign {$this->_aliases['civicrm_campaign']}
        ON {$this->_aliases['civicrm_campaign']}.id = {$this->_aliases['civicrm_mailing']}.campaign_id
      ";
    }
  }

  function where() {
    $clauses = array();
    //to avoid the sms listings
    $clauses[] = "{$this->_aliases['civicrm_mailing_stats']}.sms_provider_id IS NULL";

    foreach ($this->_columns as $tableName => $table) {
      if (array_key_exists('filters', $table)) {
        foreach ($table['filters'] as $fieldName => $field) {
          $clause = NULL;
          if (CRM_Utils_Array::value('type', $field) & CRM_Utils_Type::T_DATE) {
            $relative = CRM_Utils_Array::value("{$fieldName}_relative", $this->_params);
            $from     = CRM_Utils_Array::value("{$fieldName}_from", $this->_params);
            $to       = CRM_Utils_Array::value("{$fieldName}_to", $this->_params);

            $clause = $this->dateClause($field['name'], $relative, $from, $to, $field['type']);
          }
          else {
            $op = CRM_Utils_Array::value("{$fieldName}_op", $this->_params);

            if ($op) {
              if ($fieldName == 'relationship_type_id') {
                $clause = "{$this->_aliases['civicrm_relationship']}.relationship_type_id=" . $this->relationshipId;
              }
              else {
                $clause = $this->whereClause($field,
                  $op,
                  CRM_Utils_Array::value("{$fieldName}_value", $this->_params),
                  CRM_Utils_Array::value("{$fieldName}_min", $this->_params),
                  CRM_Utils_Array::value("{$fieldName}_max", $this->_params)
                );
              }
            }
          }

          if (!empty($clause)) {
            if (CRM_Utils_Array::value('having', $field)) {
              $havingClauses[] = $clause;
            }
            else {
              $whereClauses[] = $clause;
            }
          }
        }
      }
    }

    if (empty($whereClauses)) {
      $this->_where = "WHERE ( 1 ) ";
      $this->_having = "";
    }
    else {
      $this->_where = "\nWHERE " . implode("\n    AND ", $whereClauses);
    }


    // if ( $this->_aclWhere ) {
    // $this->_where .= " AND {$this->_aclWhere} ";
    // }

    if (!empty($havingClauses)) {
      // use this clause to construct group by clause.
      $this->_having = "\nHAVING " . implode(' AND ', $havingClauses);
    }

  }


  function orderBy() {
    $this->_orderBy = "\nORDER BY {$this->_aliases['civicrm_mailing_stats']}.finish DESC\n";
  }


  function alterDisplay(&$rows) {
    // custom code to alter rows
    $entryFound = FALSE;
    foreach ($rows as $rowNum => &$row) {
      $checkedColumns = array('civicrm_mailing_stats_mailing_stats_mailing_name', 'civicrm_mailing_mailing_id', 'civicrm_mailing_mailing_campaign_id');

      foreach ($checkedColumns as $columnName) {
        if (isset($row[$columnName])) {
          $entryFound = TRUE;
        }
      }
      if (!$entryFound) {
        return;
      }

      // Link mailing name to Civimail Report
      if (array_key_exists('civicrm_mailing_stats_mailing_stats_mailing_name', $row) &&
        !empty($row['civicrm_mailing_mailing_id'])
      ) {
        $url = CRM_Report_Utils_Report::getNextUrl('civicrm/mailing/report',
            'reset=1&mid=' . $row['civicrm_mailing_mailing_id'],
            $this->_absoluteUrl, $this->_id
        );
        $rows[$rowNum]['civicrm_mailing_stats_mailing_stats_mailing_name_link'] = $url;
        $rows[$rowNum]['civicrm_mailing_stats_mailing_stats_mailing_name_hover'] = ts("View CiviMail Report for this mailing.");
        $entryFound = TRUE;
      }

      if (!empty($row['civicrm_mailing_mailing_id'])) {
        $row['civicrm_mailing_mailing_id_link'] = CRM_Utils_System::url('civicrm/mailing/view', 'reset=1&id=' .(int) $row['civicrm_mailing_mailing_id']);
        $row['civicrm_mailing_mailing_id_hover'] = ts('View mailing') . (!empty($row['civicrm_mailing_stats_mailing_stats_mailing_name'] ? ' ' . $row['civicrm_mailing_stats_mailing_stats_mailing_name'] : ''));

      }

      if (!empty($row['civicrm_mailing_mailing_campaign_id'])) {
        $campaigns = CRM_Mailing_BAO_Mailing::buildOptions('campaign_id');
        $rows[$rowNum]['civicrm_mailing_mailing_campaign_id'] = $campaigns[$row['civicrm_mailing_mailing_campaign_id']];
      }
    }
  }

  /**
   * Get columns for mailing stats table.
   *
   * @return array
   */
  function getMailingStatColumns() {
    $specs = array(
      'mailing_name' => array(
        'title' => ts('Mailing Name'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_STRING,
      ),
      'is_completed' => array(
        'title' => ts('Is Completed'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'operatorType' => CRM_Report_Form::OP_SELECT,
        'type' => CRM_Utils_Type::T_INT,
        'options' => array(
          1 => 'Complete',
          0 => 'Incomplete',
        ),
      ),
      'created_date' => array(
        'title' => ts('Date Created'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'operatorType' => CRM_Report_Form::OP_DATE,
        'type' => CRM_Utils_Type::T_DATE,
      ),
      'start' => array(
        'title' => ts('Start Date'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'operatorType' => CRM_Report_Form::OP_DATE,
        'type' => CRM_Utils_Type::T_DATE,
      ),
      'finish' => array(
        'title' => ts('End Date'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'operatorType' => CRM_Report_Form::OP_DATE,
        'type' => CRM_Utils_Type::T_DATE,
      ),
      'recipients' => array(
        'title' => ts('Number Sent'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'operatorType' => CRM_Report_Form::OP_INT,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'delivered' => array(
        'title' => ts('Number delivered'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'send_rate' => array(
        'title' => ts('Send Rate'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'bounced' => array(
        'title' => ts('bounced'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'suppressed' => array(
        'title' => ts('Number Supressed by Provider'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'opened_total' => array(
        'title' => ts('Total Opens'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'opened_unique' => array(
        'title' => ts('Unique Opens'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'unsubscribed' => array(
        'title' => ts('unsubscribed'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'forwarded' => array(
        'title' => ts('forwarded'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'clicked_total' => array(
        'title' => ts('clicked_total'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_MONEY,
      ),
      'clicked_unique' => array(
        'title' => ts('clicked_unique'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'trackable_urls' => array(
        'title' => ts('trackable_urls'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_STRING,
      ),
      'clicked_contribution_page' => array(
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'default' => TRUE,
        'title' => ts('Clicked Contribution Page?'),
        'operatorType' => CRM_Report_Form::OP_INT,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'contribution_count' => array(
        'title' => ts('Contribution Count'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_INT,
      ),
      'contribution_total' => array(
        'title' => ts('contributions_total'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_MONEY,
      ),
    );

    return $this->buildColumns($specs, 'civicrm_mailing_stats', 'CRM_ExtendedMailingStats_BAO_MailingStats');
  }

  /**
   * Get columns for campaign table.
   *
   * @return array
   */
  function getCampaignColumns() {

    if (!CRM_Campaign_BAO_Campaign::isComponentEnabled()) {
      return array('civicrm_campaign' => array('fields' => array(), 'metadata' => array()));
    }
    $specs = array(
      'campaign_type_id' => array(
        'title' => ts('Campaign Type'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'is_group_bys' => TRUE,
        'operatorType' => CRM_Report_Form::OP_MULTISELECT,
        'options' => CRM_Campaign_BAO_Campaign::buildOptions('campaign_type_id'),
        'alter_display' => 'alterCampaignType',
      ),
      'id' => array(
        'title' => ts('Campaign'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'is_group_bys' => TRUE,
        'operatorType' => CRM_Report_Form::OP_MULTISELECT,
        'options' => CRM_Campaign_BAO_Campaign::getCampaigns(),
        'alter_display' => 'alterCampaign',
      ),
      'goal_revenue' => array(
        'title' => ts('Revenue goal'),
        'is_filters' => TRUE,
        'is_order_bys' => TRUE,
        'is_fields' => TRUE,
        'type' => CRM_Utils_Type::T_MONEY,
      ),
    );
    return $this->buildColumns($specs, 'civicrm_campaign', 'CRM_Campaign_BAO_Campaign');
  }

  /**
   * Build the columns.
   *
   * The normal report class needs you to remember to do a few things that are often erratic
   * 1) use a unique key for any field that might not be unique (e.g. start date, label)
   * - this class will always prepend an alias to the key & set the 'name' if you don't set it yourself.
   * - note that it assumes the value being passed in is the actual table fieldname
   *
   * 2) set the field & set it to no display if you don't want the field but you might want to use the field in other
   * contexts - the code looks up the fields array for data - so it both defines the field spec & the fields you want to show
   *
   * @param array $specs
   * @param string $tableName
   * @param string $tableAlias
   * @param string $daoName
   * @param array $defaults
   *
   * @return array
   */
  function buildColumns($specs, $tableName, $daoName = NULL, $tableAlias = NULL, $defaults = array(), $options = array()) {
    if (!$tableAlias) {
      $tableAlias = str_replace('civicrm_', '', $tableName);
    }
    $types = array('filters', 'group_bys', 'order_bys', 'join_filters');
    $columns = array($tableName => array_fill_keys($types, array()));
    if (!empty($daoName)) {
      if (stristr($daoName, 'BAO')) {
        $columns[$tableName]['bao'] = $daoName;
      }
      else {
        $columns[$tableName]['dao'] = $daoName;
      }
    }
    if ($tableAlias) {
      $columns[$tableName]['alias'] = $tableAlias;
    }

    foreach ($specs as $specName => $spec) {
      unset($spec['default']);
      if (empty($spec['name'])) {
        $spec['name'] = $specName;
      }

      $fieldAlias = $tableAlias . '_' . $specName;
      $columns[$tableName]['metadata'][$fieldAlias] = $spec;
      $columns[$tableName]['fields'][$fieldAlias] = $spec;
      if (isset($defaults['fields_defaults']) && in_array($spec['name'], $defaults['fields_defaults'])) {
        $columns[$tableName]['fields'][$fieldAlias]['default'] = TRUE;
      }

      if (empty($spec['is_fields']) || (isset($options['fields_excluded']) && in_array($specName, $options['fields_excluded']))) {
        $columns[$tableName]['fields'][$fieldAlias]['no_display'] = TRUE;
      }

      foreach ($types as $type) {
        if (!empty($spec['is_' . $type])) {
          $columns[$tableName][$type][$fieldAlias] = $spec;
          if (isset($defaults[$type . '_defaults']) && isset($defaults[$type . '_defaults'][$spec['name']])) {
            $columns[$tableName][$type][$fieldAlias]['default'] = $defaults[$type . '_defaults'][$spec['name']];
          }
        }
      }
    }
    return $columns;
  }
}


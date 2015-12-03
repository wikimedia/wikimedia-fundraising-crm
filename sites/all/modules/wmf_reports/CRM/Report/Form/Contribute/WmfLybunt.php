<?php
/*
 ADAPTED FROM:

 +--------------------------------------------------------------------+
 | CiviCRM version 4.2                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2012                                |
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
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2012
 */

/**
 * Extended LYBUNT report
 *
 * Lots of extra columns,
 * TODO:
 * - do lybunt after limiting by contact group and other filters
 */
class CRM_Report_Form_Contribute_WmfLybunt extends CRM_Report_Form_Contribute_Lybunt {
  public function __construct() {
    $yearsInPast   = 10;
    $yearsInFuture = 1;
    $date          = CRM_Core_SelectValues::date('custom', NULL, $yearsInPast, $yearsInFuture);
    $count         = $date['maxYear'];
    while ($date['minYear'] <= $count) {
      $optionYear[$date['minYear']] = $date['minYear'];
      $date['minYear']++;
    }

    $this->_columns = array(
      'civicrm_contact' => array(
        'dao' => 'CRM_Contact_DAO_Contact',
        'grouping' => 'contact-field',
        'fields' => array(
          'first_name' => array(
            'default' => TRUE,
          ),
          'last_name' => array(
            'default' => TRUE,
          ),
          'organization_name' => array(
            'default' => TRUE,
          ),
          'do_not_email' => array(
            'default' => TRUE,
          ),
          'do_not_phone' => array(
            'default' => TRUE,
          ),
          'do_not_mail' => array(
            'default' => TRUE,
          ),
          'do_not_sms' => array(
            'title' => ts('Do Not SMS'),
            'default' => TRUE,
          ),
          'is_opt_out' => array(
            'title' => ts('No Bulk Emails'),
            'default' => TRUE,
          ),
          'is_deceased' => array(
            'default' => TRUE,
          ),
        ),
      ),
      'civicrm_email' => array(
        'dao' => 'CRM_Core_DAO_Email',
        'grouping' => 'contact-field',
        'fields' => array(
          'email' => array(
            'default' => TRUE,
          ),
          'on_hold' => array(
            'default' => TRUE,
          ),
        ),
      ),
      'civicrm_address' => array(
        'dao' => 'CRM_Core_DAO_Address',
        'grouping' => 'contact-field',
        'fields' => array(
          'street_address' => array(
            'default' => TRUE,
          ),
          'city' => array(
            'default' => TRUE,
          ),
          'postal_code' => array(
            'default' => TRUE,
          )
        ),
      ),
      'civicrm_state_province' => array(
        'dao' => 'CRM_Core_DAO_StateProvince',
        'fields' => array(
          'name' => array(
            'title' => 'State/Province',
            'default' => TRUE,
          ),
        ),
        'grouping' => 'contact-fields',
      ),
      'civicrm_country' => array(
        'dao' => 'CRM_Core_DAO_Country',
        'fields' => array(
          'name' => array(
            'title' => 'Country',
            'default' => TRUE,
          ),
        ),
        'grouping' => 'contact-fields',
      ),
      'civicrm_phone' => array(
        'dao' => 'CRM_Core_DAO_Phone',
        'grouping' => 'contact-field',
        'fields' => array(
          'phone' => array(
            'title' => ts('Phone'),
            'default' => TRUE,
          ),
        ),
      ),
      'civicrm_contribution' => array(
        'dao' => 'CRM_Contribute_DAO_Contribution',
        'fields' => array(
          'contact_id' => array(
            'title' => ts('contactId'),
            'no_display' => TRUE,
            'required' => TRUE,
            'no_repeat' => TRUE,
          ),
          'total_amount' => array(
            'title' => ts('Total Amount'),
            'no_display' => TRUE,
            'required' => TRUE,
            'no_repeat' => TRUE,
          ),
          'receive_date' => array(
            'title' => ts('Year'),
            'no_display' => TRUE,
            'required' => TRUE,
            'no_repeat' => TRUE,
          ),
        ),
        'filters' => array(
          'yid' => array(
            'name' => 'receive_date',
            'title' => ts('This Year'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'options' => $optionYear,
            'default' => date('Y'),
          ),
          'contribution_type_id' => array(
            'title' => ts('Contribution Type'),
            'operatorType' => CRM_Report_Form::OP_MULTISELECT,
            'options' => CRM_Contribute_PseudoConstant::financialType(),
          ),
          'contribution_status_id' => array(
            'title' => ts('Contribution Status'),
            'operatorType' => CRM_Report_Form::OP_MULTISELECT,
            'options' => CRM_Contribute_PseudoConstant::contributionStatus(),
            'default' => array('1'),
          ),
        ),
      ),
      'civicrm_group' => array(
        'dao' => 'CRM_Contact_DAO_GroupContact',
        'alias' => 'cgroup',
        'filters' => array(
          'gid' => array(
            'name' => 'group_id',
            'title' => ts('Group'),
            'operatorType' => CRM_Report_Form::OP_MULTISELECT,
            'group' => TRUE,
            'options' => CRM_Core_PseudoConstant::group(),
          ),
        ),
      ),
      'wmf_donor' => array(
        'bao' => 'CRM_BAO_WmfDonor',
        'fields' => array(
          'do_not_solicit' => array(
            'default' => true,
          ),
          'lifetime_usd_total' => array(
            'default' => true,
          ),
          'last_donation_date' => array(
            'default' => true,
          ),
          'last_donation_usd' => array(
            'default' => true,
          ),
        ),
      ),
    );

    $this->_tagFilter = TRUE;

    // Skip the parent cos it would override us...  Just ah grandparent construct.
    CRM_Report_Form::__construct();
  }

  function select() {

    $this->_columnHeaders = $select = array();
    if (!isset($params['yid_value'])) {
      $this->_params['yid_value'] = date('Y');
    }
    $current_year = $this->_params['yid_value'];
    $previous_year = $current_year - 1;


    foreach ($this->_columns as $tableName => $table) {

      if (array_key_exists('fields', $table)) {
        foreach ($table['fields'] as $fieldName => $field) {

          if (CRM_Utils_Array::value('required', $field) ||
            CRM_Utils_Array::value($fieldName, $this->_params['fields'])
          ) {
            if ($fieldName == 'total_amount') {
              $select[] = "SUM({$field['dbAlias']}) as {$tableName}_{$fieldName}";

              $this->_columnHeaders["{$previous_year}"]['type'] = $field['type'];
              $this->_columnHeaders["{$previous_year}"]['title'] = $previous_year;
              
              $this->_columnHeaders["civicrm_life_time_total"]['type'] = $field['type'];
              $this->_columnHeaders["civicrm_life_time_total"]['title'] = 'LifeTime';;
            }
            elseif ($fieldName == 'receive_date') {
              $select[] = self::fiscalYearOffset($field['dbAlias']) . " as {$tableName}_{$fieldName} ";
            }
            else {
              $escapedTableName = str_replace('.', '_', $tableName);
              $select[] = "{$field['dbAlias']} as {$escapedTableName}_{$fieldName} ";
              $this->_columnHeaders["{$escapedTableName}_{$fieldName}"]['type'] = CRM_Utils_Array::value('type', $field);
              $this->_columnHeaders["{$escapedTableName}_{$fieldName}"]['title'] = CRM_Utils_Array::value('title', $field);
            }

            if (CRM_Utils_Array::value('no_display', $field)) {
              $this->_columnHeaders["{$tableName}_{$fieldName}"]['no_display'] = TRUE;
            }
          }
        }
      }
    }

    $this->_select = "SELECT  " . implode(', ', $select) . " ";
  }

  function from() {
    $this->_from = "
        FROM  civicrm_contribution  {$this->_aliases['civicrm_contribution']}
              INNER JOIN civicrm_contact {$this->_aliases['civicrm_contact']}
                      ON {$this->_aliases['civicrm_contact']}.id = {$this->_aliases['civicrm_contribution']}.contact_id
              {$this->_aclFrom}
              LEFT  JOIN civicrm_email  {$this->_aliases['civicrm_email']}
                      ON {$this->_aliases['civicrm_contact']}.id = {$this->_aliases['civicrm_email']}.contact_id AND
                         {$this->_aliases['civicrm_email']}.is_primary = 1
              LEFT  JOIN civicrm_phone  {$this->_aliases['civicrm_phone']}
                      ON {$this->_aliases['civicrm_contact']}.id = {$this->_aliases['civicrm_phone']}.contact_id AND
                         {$this->_aliases['civicrm_phone']}.is_primary = 1
              LEFT  JOIN civicrm_address  {$this->_aliases['civicrm_address']}
                      ON {$this->_aliases['civicrm_contact']}.id = {$this->_aliases['civicrm_address']}.contact_id AND
                         {$this->_aliases['civicrm_address']}.is_primary = 1
              LEFT JOIN civicrm_state_province {$this->_aliases['civicrm_state_province']}
                      ON {$this->_aliases['civicrm_address']}.state_province_id = {$this->_aliases['civicrm_state_province']}.id AND
                         {$this->_aliases['civicrm_address']}.is_primary = 1
              LEFT JOIN civicrm_country {$this->_aliases['civicrm_country']}
                      ON {$this->_aliases['civicrm_address']}.country_id = {$this->_aliases['civicrm_country']}.id AND
                         {$this->_aliases['civicrm_address']}.is_primary = 1
              LEFT JOIN wmf_donor {$this->_aliases['wmf_donor']}
                      ON {$this->_aliases['wmf_donor']}.entity_id = {$this->_aliases['civicrm_contact']}.id ";
  }

  function where() {

    $this->_statusClause = "";
    $clauses             = array($this->_aliases['civicrm_contribution'] . '.is_test = 0');
    $current_year        = $this->_params['yid_value'];
    $previous_year       = $current_year - 1;

    foreach ($this->_columns as $tableName => $table) {
      if (array_key_exists('filters', $table)) {
        foreach ($table['filters'] as $fieldName => $field) {
          $clause = NULL;
          if ($fieldName == 'yid') {
            if($this->_params['yid_op'] == 'calendar') {
                $clause = "YEAR({$this->_aliases['wmf_donor']}.last_donation_date) = $previous_year";
            }
            else {
              $clause = "{$this->_aliases['wmf_donor']}.is_{$current_year}_donor = 0
                           AND {$this->_aliases['wmf_donor']}.is_{$previous_year}_donor = 1";
            }
          }
          elseif (CRM_Utils_Array::value('type', $field) & CRM_Utils_Type::T_DATE) {
            $relative = CRM_Utils_Array::value("{$fieldName}_relative", $this->_params);
            $from     = CRM_Utils_Array::value("{$fieldName}_from", $this->_params);
            $to       = CRM_Utils_Array::value("{$fieldName}_to", $this->_params);

            if ($relative || $from || $to) {
              $clause = $this->dateClause($field['name'], $relative, $from, $to, $field['type']);
            }
          }
          else {
            $op = CRM_Utils_Array::value("{$fieldName}_op", $this->_params);
            if ($op) {
              $clause = $this->whereClause($field,
                $op,
                CRM_Utils_Array::value("{$fieldName}_value", $this->_params),
                CRM_Utils_Array::value("{$fieldName}_min", $this->_params),
                CRM_Utils_Array::value("{$fieldName}_max", $this->_params)
              );
              if (($fieldName == 'contribution_status_id' || $fieldName == 'contribution_type_id') && !empty($clause)) {
                $this->_statusClause .= " AND " . $clause;
              }
            }
          }

          if (!empty($clause)) {
            $clauses[] = $clause;
          }
        }
      }
    }

    $this->_where = 'WHERE ' . implode(' AND ', $clauses);

    if ($this->_aclWhere) {
      $this->_where .= " AND {$this->_aclWhere} ";
    }
  }

    function orderBy() {

      $this->_orderBy = "ORDER BY civicrm_contribution_total_amount DESC";
    }
    
public function groupBy() {
    $this->_groupBy = "GROUP BY  {$this->_aliases['civicrm_contribution']}.contact_id, " .
      self::fiscalYearOffset($this->_aliases['civicrm_contribution'] .
        '.receive_date') . ", civicrm_contribution_total_amount DESC WITH ROLLUP";
    $this->assign('chartSupported', TRUE);
  }
    
    function postProcess() {

    // get ready with post process params
    $this->beginPostProcess();

    // get the acl clauses built before we assemble the query
    $this->buildACLClause($this->_aliases['civicrm_contact']);
    $this->select();
    $this->from();
    $this->where();
    $this->groupBy();
    //$this->orderBy();

    $rows = $this->_contactIds = array();
    $this->limit();
    $getContacts = "SELECT SQL_CALC_FOUND_ROWS {$this->_aliases['civicrm_contact']}.id as cid {$this->_from} {$this->_where}  GROUP BY {$this->_aliases['civicrm_contact']}.id {$this->_limit}";
    $this->addToDeveloperTab($getContacts);
    $dao = CRM_Core_DAO::executeQuery($getContacts);

    while ($dao->fetch()) {
      $this->_contactIds[] = $dao->cid;
    }
    $dao->free();
    if (empty($this->_params['charts'])) {
      $this->setPager();
    }

    if (!empty($this->_contactIds) || !empty($this->_params['charts'])) {
      $sql = "{$this->_select} {$this->_from} WHERE {$this->_aliases['civicrm_contact']}.id IN (" . implode(',', $this->_contactIds) . ")
        AND {$this->_aliases['civicrm_contribution']}.is_test = 0 {$this->_statusClause} {$this->_groupBy}";
      $this->addToDeveloperTab($sql);
      $dao = CRM_Core_DAO::executeQuery($sql);
      $current_year = $this->_params['yid_value'];
      $previous_year = $current_year - 1;

      while ($dao->fetch()) {

        if (!$dao->civicrm_contribution_contact_id) {
          continue;
        }

        $row = array();
        foreach ($this->_columnHeaders as $key => $value) {
          if (property_exists($dao, $key)) {
            $rows[$dao->civicrm_contribution_contact_id][$key] = $dao->$key;
          }
        }

        if ($dao->civicrm_contribution_receive_date) {
          if ($dao->civicrm_contribution_receive_date == $previous_year) {
            $rows[$dao->civicrm_contribution_contact_id][$dao->civicrm_contribution_receive_date] = $dao->civicrm_contribution_total_amount;
          }
        }
        else {
          $rows[$dao->civicrm_contribution_contact_id]['civicrm_life_time_total'] = $dao->civicrm_contribution_total_amount;
        }
      }
      $dao->free();
    }

    $this->formatDisplay($rows, FALSE);

    // assign variables to templates
    $this->doTemplateAssignment($rows);

    // do print / pdf / instance stuff if needed
    $this->endPostProcess($rows);
  }
  
  function alterDisplay(&$rows) {
    // Prevent parent behavior
  }
}

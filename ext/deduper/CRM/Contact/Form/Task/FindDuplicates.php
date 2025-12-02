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
 * @copyright CiviCRM LLC (c) 2004-2017
 */

/**
 * This class provides the functionality to find potential matches.
 */
class CRM_Contact_Form_Task_FindDuplicates extends CRM_Core_Form {

  /**
   * Name of temporary table holding contacts.
   *
   * @var string
   */
  public $_componentTable;

  /**
   * Selected contact ids.
   *
   * @var array
   */
  public $_contactIds = array();

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    CRM_Contact_Form_Task::preProcessCommon($this);
    // Some issues with how we are passing these need dealing with at some stage.
    // ie. switch to dedupe table first & load. For now limit.
    $limit = 100;
    $contactIDs = $this->_contactIds;
    if (count($contactIDs) > $limit) {
      $chunked = array_chunk($contactIDs, $limit);
      CRM_Core_Session::setStatus(ts("Only the first %1 have been selected for deduping", [1 => $limit]));
      $contactIDs = $chunked[0];
    }
    $contactType = CRM_Core_DAO::singleValueQuery(
      "SELECT GROUP_CONCAT(DISTINCT contact_type) FROM civicrm_contact WHERE id IN (%1)", [
      1 => [implode(',', $contactIDs), 'CommaSeparatedIntegers'],
      2 => [$limit, 'Integer']
    ]);

    try {
      $rule_group_id = civicrm_api3('RuleGroup', 'getvalue', array(
        'contact_type' => $contactType,
        'used' => 'Unsupervised',
        'return' => 'id',
        'options' => array('limit' => 1),
      ));
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::statusBounce(ts('It was not possible to identify a default rule that was applicable to all selected contacts. You must choose only one contact type. You chose %1', array($contactType)));
    }

    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contact/dedupefind', array(
      'reset' => 1,
      'action' => 'update',
      'rgid' => $rule_group_id,
      'criteria' => json_encode(array('contact' => array('id' => array('IN' => $contactIDs)))),
      'limit' => count($contactIDs),
    )));
  }
}

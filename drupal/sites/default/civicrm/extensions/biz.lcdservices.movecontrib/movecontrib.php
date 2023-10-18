<?php

require_once 'movecontrib.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function movecontrib_civicrm_config(&$config) {
  _movecontrib_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function movecontrib_civicrm_install() {
  _movecontrib_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function movecontrib_civicrm_enable() {
  _movecontrib_civix_civicrm_enable();
}

function movecontrib_civicrm_searchColumns($objectName, &$headers, &$rows, &$selector) {
  /*Civi::log()->debug('movecontrib_civicrm_searchColumns', array(
    'objectName' => $objectName,
    '$headers' => $headers,
    '$rows' => $rows,
    //'$selector' => $selector,
  ));*/

  if ($objectName == 'contribution' && CRM_Core_Permission::check('allow Move Contribution')) {
    foreach ($rows as &$row) {
      //action column is either a series of links, or a series of links plus a subset
      //unordered list (more button) -- all of which is enclosed in a span
      //we want to inject our option at the end, regardless, so we look for the existence
      //of a <ul> tag and adjust our injection accordingly
      $url = CRM_Utils_System::url('civicrm/movecontrib', "reset=1&id={$row['contribution_id']}");
      $urlLink = "<a href='{$url}' class='action-item crm-hover-button medium-popup move-contrib'>Move Contribution</a>";
      if (strpos($row['action'], '</ul>') !== FALSE) {
        $row['action'] = str_replace('</ul></span>', '<li>'.$urlLink.'</li></ul></span>', $row['action']);
      }
      else {
        $row['action'] = str_replace('</span>', $urlLink.'</span>', $row['action']);
      }
    }
  }
}

function movecontrib_civicrm_searchTasks($objectType, &$tasks) {
  /*Civi::log()->debug('movecontrib_civicrm_searchTasks', array(
    '$objectType' => $objectType,
    '$tasks' => $tasks,
  ));*/

  if ($objectType == 'contribution' && CRM_Core_Permission::check('allow Move Contribution')) {
    $tasks[] = array(
      'title' => 'Move contributions',
      'class' => 'CRM_LCD_MoveContrib_Form_Task',
      'result' => TRUE,
    );
  }
}

/**
 *Implementation of hook_civicrm_permission
 * @param array $permissions
 */
function movecontrib_civicrm_permission(&$permissions) {
  $prefix = ts('Move Contributions') . ': '; // name of extension or module
  $permissions['allow Move Contribution'] = $prefix . ts('allow Move Contribution');
}

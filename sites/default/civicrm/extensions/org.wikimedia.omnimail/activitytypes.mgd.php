<?php
/**
 * Created by PhpStorm.
 * User: eileen
 * Date: 17/06/2014
 * Time: 10:14 PM
 */
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC/Hook+Reference
return array(
  0 =>
    array(
      'name' => 'unsubscribe',
      'entity' => 'option_value',
      'params' =>
        array(
          'version' => 3,
          'option_group_id' => 'activity_type',
          'label' => ts('Unsubscribe'),
          'name' => 'unsubscribe',
          'is_reserved' => TRUE,
          'is_active' => TRUE,
        ),
    ),
);

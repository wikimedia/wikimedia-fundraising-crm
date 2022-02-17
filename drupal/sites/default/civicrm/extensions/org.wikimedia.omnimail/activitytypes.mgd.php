<?php
/**
 * Created by PhpStorm.
 * User: eileen
 * Date: 17/06/2014
 * Time: 10:14 PM
 */
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 =>
    [
      'name' => 'unsubscribe',
      'entity' => 'option_value',
      'cleanup' => 'never',
      'params' =>
        [
          'version' => 3,
          'option_group_id' => 'activity_type',
          'label' => ts('Unsubscribe'),
          'name' => 'unsubscribe',
          'is_reserved' => TRUE,
          'is_active' => TRUE,
          'cleanup' => 'never',
        ],
    ],
];

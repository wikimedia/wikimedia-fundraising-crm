<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * Field handler for phone field
 *
 * @ingroup civicrm_field_handlers
 */
class civicrm_handler_field_phone extends civicrm_handler_field_location {
  public static $_phoneType;

  public function construct() {
    parent::construct();
    if (!self::$_phoneType) {
      if (!civicrm_initialize()) {
        return;
      }
      self::$_phoneType = CRM_Core_DAO_Phone::buildOptions('phone_type_id');
    }
  }

  public function option_definition() {
    $options = parent::option_definition();
    $options['phone_type'] = array('default' => 0);
    return $options;
  }

  public function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);
    $phoneOptions = array(0 => 'Any');
    foreach (self::$_phoneType as $id => $type) {
      $phoneOptions[$id] = $type;
    }
    $form['phone_type'] = array(
      '#type' => 'radios',
      '#title' => 'Phone type for this field',
      '#options' => $phoneOptions,
      '#description' => t('Phone type to be displayed for this field'),
      '#default_value' => $this->options['phone_type'],
      '#fieldset' => 'location_choices',
    );
  }

  public function join_phone($join = array()) {
    $extra = array();
    if (isset($join->extra)) {
      $extra = $join->extra;
    }
    if (isset($this->options['phone_type']) && $this->options['phone_type']) {
      $extra[] = array(
        'value' => $this->options['phone_type'],
        'numeric' => TRUE,
        'field' => 'phone_type_id',
        'operator' => '=',
      );
    }
    if (!empty($extra)) {
      $join->extra = $extra;
    }
    return $join;
  }

  public function get_join() {
    $join = parent::get_join();
    $join = $this->join_phone($join);
    return $join;
  }

  public function ensure_my_table() {
    if (!isset($this->table_alias)) {
      if (!method_exists($this->query, 'ensure_table')) {
        vpr_trace();
        exit;
      }
      $join = $this->get_join();
      if (empty($this->relationship)) {
        //https://www.drupal.org/node/2617032
        $this->table_alias = $this->query->add_table($this->table, $this->relationship, $join);
      }
      else {
        $this->table_alias = $this->query->ensure_table($this->table, $this->relationship, $join);
      }
    }
    return $this->table_alias;
  }

}

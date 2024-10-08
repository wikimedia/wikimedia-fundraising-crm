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
 * Generic field handler for address and location fields
 *
 * @ingroup civicrm_field_handlers
 */
class civicrm_handler_field_location extends civicrm_handler_field {
  public static $_locationTypes;
  public static $_locationOps;
  public static $location_op;

  public function construct() {
    parent::construct();
    if (!self::$_locationTypes) {
      if (!civicrm_initialize()) {
        return;
      }
      require_once 'CRM/Core/PseudoConstant.php';
      self::$_locationTypes = CRM_Core_DAO_Address::buildOptions('location_type_id');
      self::$_locationOps = array(0 => 'AND', 1 => 'OR');
    }
  }

  public function option_definition() {
    $options = parent::option_definition();
    $options['location_type'] = array('default' => 0);
    $options['location_op'] = array('default' => 0);
    $options['is_primary'] = array('default' => '');
    return $options;
  }

  public function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);

    $locationOptions = array(0 => 'Any');
    foreach (self::$_locationTypes as $id => $type) {
      $locationOptions[$id] = $type;
    }
    $label = $this->label();
    $form['label'] = array(
      '#type' => 'textfield',
      '#title' => t('Label'),
      '#default_value' => $label,
      '#dependency' => array(
        'edit-options-custom-label' => array(1),
      ),
      '#weight' => -102,
    );
    $form['location_choices'] = array(
      '#type' => 'fieldset',
      '#title' => t('CiviCRM Location Display Options'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => '<strong>Note:</strong> it is possible to choose display options that result in nothing being displayed for this field',
      '#weight' => 1,
    );
    $form['location_op'] = array(
      '#type' => 'select',
      '#title' => 'And/Or',
      '#options' => self::$_locationOps,
      '#description' => t('Display option are joined by And/Or?'),
      '#default_value' => $this->options['location_op'],
      '#fieldset' => 'location_choices',
    );
    $form['location_type'] = array(
      '#type' => 'radios',
      '#title' => 'Location type for this field',
      '#options' => $locationOptions,
      '#description' => t('Location type to be displayed for this field'),
      '#default_value' => $this->options['location_type'],
      '#fieldset' => 'location_choices',
    );
    $form['is_primary'] = array(
      '#type' => 'checkbox',
      '#title' => 'Show only Primary record?',
      '#options' => array(0 => 'No', 1 => 'Yes'),
      '#description' => t('Check above box if you want only the <strong>Primary</strong> record displayed.'),
      '#default_value' => $this->options['is_primary'],
      '#fieldset' => 'location_choices',
    );
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

  public function location_extras() {
    $extra = array();
    if (!empty($this->options['location_type'])) {
      $extra[] = array(
        'value' => $this->options['location_type'],
        'numeric' => TRUE,
        'field' => 'location_type_id',
        'operator' => '=',
      );
    }
    if (!empty($this->options['is_primary'])) {
      $extra[] = array(
        'value' => $this->options['is_primary'],
        'numeric' => TRUE,
        'field' => 'is_primary',
        'operator' => '=',
      );
    }
    return $extra;
  }

  public function join_location($join = array()) {
    $extra = array();
    if (isset($join->extra)) {
      $extra = $join->extra;
    }
    $extra = array_merge($extra, $this->location_extras());
    if (!empty($extra)) {
      $join->extra = $extra;
      $join->extra_type = self::$_locationOps[$this->options['location_op']];
    }
    return $join;
  }

  public function get_join() {
    $join = parent::get_join();
    $join = $this->join_location($join);
    return $join;
  }

}

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
 * Field handler to provide relationship to CiviCRM Website.
 *
 * @ingroup civicrm_relationship_handlers
 */
class civicrm_handler_relationship_website extends civicrm_handler_relationship_location {

  public static $_websiteType;

  public function construct() {
    parent::construct();
    if (!self::$_websiteType) {
      if (!civicrm_initialize()) {
        return;
      }
      self::$_websiteType = CRM_Core_DAO_Website::buildOptions('website_type_id');
    }
  }

  public function option_definition() {
    $options = parent::option_definition();
    $options['website_type'] = array('default' => 0);
    return $options;
  }

  public function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);
    $websiteOptions = array(0 => 'Any');
    foreach (self::$_websiteType as $id => $type) {
      $websiteOptions[$id] = $type;
    }
    $form['website_type'] = array(
      '#type' => 'radios',
      '#title' => 'Website type for this relationship',
      '#options' => $websiteOptions,
      '#description' => t('Website type to be used for this relationship'),
      '#default_value' => $this->options['website_type'],
      '#fieldset' => 'location_choices',
    );
  }

  public function join_website($join = array()) {
    $extra = array();
    if (isset($join->extra)) {
      $extra = $join->extra;
    }
    if (isset($this->options['website_type']) && $this->options['website_type']) {
      $extra[] = array(
        'value' => $this->options['website_type'],
        'numeric' => TRUE,
        'field' => 'website_type_id',
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
    $join = $this->join_website($join);
    return $join;
  }

  /**
   * Called to implement a relationship in a query.
   */
  public function query() {
    $join = $this->get_join();
    $this->alias = $this->query->add_table($this->table, $this->relationship, $join);
    //register relationship
    $this->view->relationship[$this->options['id']]->alias = $this->alias;
    $this->query->relationships[$this->alias] = array(
      'link' => $this->relationship,
      'table' => $this->table,
      'base' => $this->table,
    );
  }

}

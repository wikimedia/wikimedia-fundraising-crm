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
 * Heavily based on anonymous code contribution. No claim.
 *
 * civicrm_handler_field_event_link.inc
 * Displays Text Data and can be set to link to various pages related to the event.
 *
 */

/**
 * Field handler to provide simple renderer that allows linking to an Event Page or it's Management Page.
 */
class civicrm_handler_field_event_link extends views_handler_field {

  /**
   * Constructor to provide additional field to add.
   */
  public function construct() {
    parent::construct();
    $this->additional_fields['id'] = 'id';
  }

  public function option_definition() {
    $options = parent::option_definition();
    $options['link_to_civicrm_event'] = array('default' => 'none');
    $options['custom_link'] = array('default' => '');
    return $options;
  }

  /**
   * Provide link to node option
   */
  public function options_form(&$form, &$form_state) {
    parent::options_form($form, $form_state);
    $form['link_to_civicrm_event'] = array(
      '#type' => 'select',
      '#title' => t('Choose where to link this field'),
      '#options' => array(
        'none' => t("Don't Link this Field"),
        'page' => t('Link to Event Page'),
        'registration' => t('Link to Event Registration'),
        'config' => t('Link to Event Configuration'),
        'participants' => t('Link to Event Participants'),
        'custom' => t('Link to a Custom Node'),
      ),
      '#default_value' => !empty($this->options['link_to_civicrm_event']),
    );

    $form['custom_link'] = array(
      '#type' => 'textfield',
      '#title' => t('Custom Link to a Drupal Node'),
      '#description' => t('Link to a Drupal Node or View, from the base path. The Event ID will be appended to the end of the link.'),
      '#default_value' => isset($this->options['custom_link']) ? $this->options['custom_link'] : '',
      '#dependency' => array('link_to_civicrm_event' => array('custom')),
    );
  }

  /**
   * Render whatever the data is as a link to the node.
   *
   * Data should be made XSS safe prior to calling this function.
   */
  public function render_link($data, $values) {

    $link_choice = $this->options['link_to_civicrm_event'];

    $custom_link = NULL;
    if ($link_choice == 'custom') {
      $custom_link = $this->options['custom_link'];
    }

    switch ($link_choice) {

      // LINKING TO EVENT PAGE (PUBLICLY ACCESSIBLE)

      case 'page':
        if (user_access('view event info') && $data !== NULL && $data !== '') {
          return civicrm_views_href($data,
            'civicrm/event/info',
            "reset=1&id={$values->{$this->aliases['id']}}"
          );
        }

        // LINKING TO EVENT REGISTRATION PAGE
      case 'registration':
        if (user_access('register for events') && $data !== NULL && $data !== '') {
          return civicrm_views_href($data,
            'civicrm/event/register',
            "reset=1&id={$values->{$this->aliases['id']}}"
          );
        }

        // LINKING TO EVENT CONFIG PAGE
      case 'config':
        if (user_access('access CiviEvent') && $data !== NULL && $data !== '') {
          return civicrm_views_href($data,
            'civicrm/event/manage/eventInfo',
            "action=update&id={$values->{$this->aliases['id']}}&reset=1"
          );
        }

        // LINKING TO EVENT PARTICIPANTS LIST
      case 'participants':
        if (user_access('access CiviEvent') && $data !== NULL && $data !== '') {
          return civicrm_views_href($data,
            'civicrm/event/search',
            "reset=1&force=1&event={$values->{$this->aliases['id']}}"
          );
        }

        // CUSTOM NODE LINKAGE, GOOD FOR LINKING TO OTHER VIEWS WITH AN ARGUMENT
      case 'custom':
        if ($custom_link !== NULL && $custom_link !== '' && $data !== NULL && $data !== '') {
          return l($data, $custom_link . "{$values->{$this->aliases['id']}}", array('html' => TRUE));
        }

        // IF THE OTHER CASES AREN'T IN PLAY, THEN JUST PRINT THE DATA
      default:
        return $data;
    }
  }

  public function render($values) {
    return $this->render_link(check_plain($values->{$this->field_alias}), $values);
  }

}

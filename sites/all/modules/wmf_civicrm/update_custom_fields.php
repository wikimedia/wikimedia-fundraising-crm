<?php

/**
 * Update the receipt to match the version in the templates folder.
 */
function _wmf_civicrm_update_custom_fields() {
  civicrm_initialize();
  $customGroup = civicrm_api3('CustomGroup', 'get', array('name' => 'Prospect'));
  if (!$customGroup['count']) {
    $customGroup = civicrm_api3('CustomGroup', 'create', array(
      'name' => 'Prospect',
      'title' => 'Prospect',
      'extends' => 'Contact',
      'style' => 'tab',
      'is_active' => 1,
      ));
   }
    // We mostly are trying to ensure a unique weight since weighting can be re-orded in the UI but it gets messy
    // if they are all set to 1.
    $weight = CRM_Core_DAO::singleValueQuery('SELECT max(weight) FROM civicrm_custom_field WHERE custom_group_id = %1',
        array(1 => array($customGroup['id'], 'Integer'))
    );

    foreach (_wmf_civicrm_get_prospect_fields() as $field) {
      if (!civicrm_api3('CustomField', 'getcount', array(
        'custom_group_id' => $customGroup['id'],
        'name' => $field['name'],
        ))){
        $weight++;
        civicrm_api3('CustomField', 'create', array_merge(
          $field,
          array(
            'custom_group_id' => $customGroup['id'],
            'weight' => $weight,
          )
        ));
      }
    }
}

function _wmf_civicrm_get_prospect_fields() {
  return array(
      'ask_amount' => array(
          'name' => 'ask_amount',
          'label' => 'Ask Amount',
          'data_type' => 'Money',
          'html_type' => 'Text',
          'is_searchable' => 1,
          'is_search_range' => 1,
      ),
      'expected_amount' => array(
          'name' => 'expected_amount',
          'label' => 'Expected Amount',
          'data_type' => 'Money',
          'html_type' => 'Text',
          'is_searchable' => 1,
          'is_search_range' => 1,
      ),
      'likelihood' => array(
          'name' => 'likelihood',
          'label' => 'Likelihood (%)',
          'data_type' => 'Integer',
          'html_type' => 'Text',
          'is_searchable' => 1,
          'is_search_range' => 1,
      ),
      'expected_close_date' => array(
          'name' => 'expected_close_date',
          'label' => 'Expected Close Date',
          'data_type' => 'Date',
          'html_type' => 'Select Date',
          'is_searchable' => 1,
          'is_search_range' => 1,
      ),
      'close_date' => array(
          'name' => 'close_date',
          'label' => 'Close Date',
          'data_type' => 'Date',
          'html_type' => 'Select Date',
          'is_searchable' => 1,
          'is_search_range' => 1,
      ),
      'next_step' => array(
          'name' => 'next_step',
          'label' => 'Next Step',
          'data_type' => 'Memo',
          'html_type' => 'RichTextEditor',
          'note_columns' => 60,
          'note_rows' => 4,
      ),
      'Disc_Income_Decile' => array(
          'name' => 'Disc_Income_Decile',
          'label' => 'Disc Income Decile',
          'data_type' => 'String',
          'html_type' => 'Select',
          'is_searchable' => 1,
          'text_length' => 255,
          'note_columns' => 60,
          'note_rows' => 4,
          'option_values' => array(
              'A' => 'A',
              'B' => 'B',
              'C' => 'C',
              'D' => 'D',
              'E' => 'E',
              'F' => 'F',
              'G' => 'G',
              'H' => 'H',
              'I' => 'I',
          )
      ),
  );
}

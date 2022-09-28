<?php

class CRM_Relationshipblock_Utils_RelationshipBlock {

  /**
   * Get existing relationships for the contact.
   *
   * @param int $contactID
   *
   * @return array
   */
  public static function getExistingRelationships($contactID) {
    $displayedRelationships = self::getDisplayedRelationshipTypes($contactID);
    if (empty($displayedRelationships)) {
      // Nothing to do here. Move along.
      return [];
    }
    $displayedRelationships = CRM_Utils_Array::rekey($displayedRelationships, 'id');
    $existingRelationships = civicrm_api3('Relationship', 'get', [
      'relationship_type_id' => ['IN' => array_keys($displayedRelationships)],
      'is_active' => 1,
      'contact_id_a' => $contactID,
      'contact_id_b' => $contactID,
      'contact_id_a.is_deleted' => ['!=' => 1],
      'contact_id_b.is_deleted' => ['!=' => 1],
      'check_permissions' => TRUE,
      'return' => [
        'id',
        'relationship_type_id',
        'contact_id_a',
        'contact_id_b',
        'contact_id_a.display_name',
        'contact_id_b.display_name',
        'contact_id_a.is_deceased',
        'contact_id_b.is_deceased',
        'end_date',
        'start_date',
      ],
      'options' => [
        'limit' => 0,
        'sort' => 'relationship_type_id.label_a_b ASC',
        'or' => [['contact_id_a', 'contact_id_b']],
      ],
    ]);
    $exclude_expired_field_id = civicrm_api3('CustomField', 'getvalue', [
      'name' => 'relationship_block_exclude_expired',
      'return' => 'id',
    ]);
    $exclude_pending_field_id = civicrm_api3('CustomField', 'getvalue', [
      'name' => 'relationship_block_exclude_pending',
      'return' => 'id',
    ]);
    $ret = [];
    foreach ($existingRelationships['values'] as $rel) {
      // Only include expired and pending relationships, if they are configred
      // to be displayed for the relationship type.
      if (
        (
          empty($displayedRelationships[$rel['relationship_type_id']]['custom_' . $exclude_expired_field_id])
          || !isset($rel['end_date'])
          || $rel['end_date'] > date('Y-m-d')
        )
        && (
          empty($displayedRelationships[$rel['relationship_type_id']]['custom_' . $exclude_pending_field_id])
          || !isset($rel['start_date'])
          || $rel['start_date'] < date('Y-m-d')
        )
      ) {
        $relationshipType = $displayedRelationships[$rel['relationship_type_id']];
        $dir = $rel['contact_id_a'] == $contactID ? 'a_b' : 'b_a';
        list($a, $b) = explode('_', $dir);
        // Make all bidirectional relationships appear as the A side to make the form processing simpler
        if ($relationshipType['bi']) {
          $dir = 'a_b';
        }
        $key = $rel['relationship_type_id'] . '_' . $dir;
        $ret[$key] = isset($ret[$key]) ? $ret[$key] : [];
        $ret[$key] += [
          'relationship_type_id' => $rel['relationship_type_id'],
          'relationship_type' => $displayedRelationships[$rel['relationship_type_id']]["label_$dir"],
          'contacts' => [],
        ];
        $ret[$key]['contacts'][$rel["contact_id_$b"]] = [
          'contact_id' => $rel["contact_id_$b"],
          'relationship_id' => $rel['id'],
          'display_name' => $rel["contact_id_$b.display_name"],
          'is_deceased' => $rel["contact_id_$b.is_deceased"],
        ];
      }
    }
    return $ret;
  }

  /**
   * Get key relationship types valid for a given contact.
   *
   * @param int $contactId
   *
   * @return array
   */
  public static function getDisplayedRelationshipTypes($contactId) {
    if (!isset(\Civi::$statics[__CLASS__]['displayed_relationship_types'][$contactId])) {
      $displayedRelationships = [];
      $allValidRelationships = \CRM_Contact_BAO_Relationship::getContactRelationshipType($contactId);
      $isDisplayFieldID = civicrm_api3('CustomField', 'getvalue', [
        'name' => 'is_relationship_block_on_summary',
        'return' => 'id',
      ]);
      $allDisplayedRelationships = civicrm_api3('RelationshipType', 'get', [
        'custom_' . $isDisplayFieldID => 1,
        'options' => ['limit' => 0],
      ])['values'];
      foreach ($allValidRelationships as $key => $label) {
        list($id, $dir) = explode('_', $key, 2);
        if (isset($allDisplayedRelationships[$id])) {
          $displayedRelationships[$key] = $allDisplayedRelationships[$id] + [
            'label' => $label,
            'dir' => $dir,
            'bi' => $allDisplayedRelationships[$id]['label_b_a'] == $allDisplayedRelationships[$id]['label_a_b'],
          ];
        }
      }
      \Civi::$statics[__CLASS__]['displayed_relationship_types'][$contactId] = $displayedRelationships;
    }
    return \Civi::$statics[__CLASS__]['displayed_relationship_types'][$contactId];
  }

}

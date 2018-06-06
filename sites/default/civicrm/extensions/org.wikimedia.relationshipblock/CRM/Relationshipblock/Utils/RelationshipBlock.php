<?php

class CRM_Relationshipblock_Utils_RelationshipBlock {
  /**
   * Append detail (label & related contact name) to relationship array.
   *
   * @param array $existingRelationship
   * @param int $contactID
   * @param array $displayedRelationships
   *
   * @return mixed
   */
  protected static function appendDetailToRelationship(&$existingRelationship, $contactID, $displayedRelationships) {
    $dir = $existingRelationship['contact_id_a'] === $contactID ? 'a_b' : 'b_a';
    list($a, $b) = explode('_', $dir);
    $existingRelationship['relationship_type'] = $displayedRelationships[$existingRelationship['relationship_type_id']]["label_$dir"];
    $existingRelationship['relation_display_name'] = $existingRelationship["contact_id_$b.display_name"];
    $existingRelationship['other_contact_id'] = $existingRelationship["contact_id_$b"];
    $existingRelationship['dir'] = $dir;
  }

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
      'return' => ['id', 'relationship_type_id', 'contact_id_a', 'contact_id_b', 'contact_id_a.display_name', 'contact_id_b.display_name'],
      'options' => ['or' => [['contact_id_a', 'contact_id_b']]],
    ]);
    $ret = [];
    foreach ($existingRelationships['values'] as $rel) {
      self::appendDetailToRelationship($rel, $contactID, $displayedRelationships);
      $ret[$rel['relationship_type_id'] . '_' . $rel['dir']] = $rel;
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
          $displayedRelationships[$key] = $allDisplayedRelationships[$id] + array(
              'label' => $label,
              'dir' => $dir,
              'bi' => $allDisplayedRelationships[$id]['label_b_a'] == $allDisplayedRelationships[$id]['label_a_b'],
            );
        }
      }
      \Civi::$statics[__CLASS__]['displayed_relationship_types'][$contactId] = $displayedRelationships;
    }
    return \Civi::$statics[__CLASS__]['displayed_relationship_types'][$contactId];
  }
}
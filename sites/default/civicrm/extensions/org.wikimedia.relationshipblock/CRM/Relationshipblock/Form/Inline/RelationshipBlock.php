<?php

use CRM_Relationshipblock_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Relationshipblock_Form_Inline_RelationshipBlock extends CRM_Contact_Form_Inline {

  /**
   * Form for editing key relationships
   */
  public function buildQuickForm() {
    foreach (CRM_Relationshipblock_Utils_RelationshipBlock::getDisplayedRelationshipTypes($this->_contactId) as $key => $relationshipType) {
      $params = [];
      list($a, $b) = explode('_', $relationshipType['dir']);
      if (!empty($relationshipType["contact_type_$b"]) && in_array($relationshipType["contact_type_$b"], ['Individual', 'Household', 'Organizion'])) {
        $params['contact_type'] = $relationshipType["contact_type_$b"];
      }
      if (!empty($relationshipType["contact_sub_type_$b"])) {
        $params['contact_sub_type'] = $relationshipType["contact_sub_type_$b"];
      }
      $props = array(
        'api' => array('params' => $params),
        'create' => TRUE,
      );
      $this->addEntityRef($key, $relationshipType['label'], $props, FALSE);
    }

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  /**
   * Save relationships
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function postProcess() {
    $values = $this->exportValues();
    $relationshipTypes = CRM_Relationshipblock_Utils_RelationshipBlock::getDisplayedRelationshipTypes($this->_contactId);
    $existingRelationships = CRM_Relationshipblock_Utils_RelationshipBlock::getExistingRelationships($this->_contactId);
    foreach ($relationshipTypes as $key => $relType) {
      // End old relationships
      if (!empty($existingRelationships[$key]) && $existingRelationships[$key]['other_contact_id'] != CRM_Utils_Array::value($key, $values)) {
        civicrm_api3('Relationship', 'create', [
          'id' => $existingRelationships[$key]['id'],
          'end_date' => 'now',
          'is_active' => 0,
        ]);
      }
      // Create new relationships
      $existingRelationship = CRM_Utils_Array::value($key, $existingRelationships);
      if (!empty($values[$key]) && (!$existingRelationship || $existingRelationship['other_contact_id'] != $values[$key])) {
        list($a, $b) = explode('_', $relType['dir']);
        civicrm_api3('Relationship', 'create', [
          'relationship_type_id' => $relType['id'],
          "contact_id_$a" => $this->_contactId,
          "contact_id_$b" => $values[$key],
          'start_date' => 'now',
          'is_active' => 1,
        ]);
      }
    }
    $this->ajaxResponse['updateTabs'] = array(
      '#tab_rel' => CRM_Contact_BAO_Contact::getCountComponent('rel', $this->_contactId),
    );
    $this->log();
    $this->response();
  }

  /**
   * Get existing relationships for form
   */
  public function setDefaultValues() {
    $defaults = [];
    $relationshipTypes = CRM_Relationshipblock_Utils_RelationshipBlock::getDisplayedRelationshipTypes($this->_contactId);
    $relationshipTypes = CRM_Utils_Array::rekey($relationshipTypes, 'id');
    $existingRelationships = CRM_Relationshipblock_Utils_RelationshipBlock::getExistingRelationships($this->_contactId);
    foreach ($existingRelationships as $existingRelationship) {
      $relType = $relationshipTypes[$existingRelationship['relationship_type_id']];
      $dir = $relType['bi'] ? $relType['dir'] : $existingRelationship['dir'];
      $defaults[$relType['id'] . '_' . $dir] = $existingRelationship['other_contact_id'];
    }
    return $defaults;
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}

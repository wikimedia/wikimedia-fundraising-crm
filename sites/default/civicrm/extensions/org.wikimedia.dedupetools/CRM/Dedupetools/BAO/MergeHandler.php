<?php

use CRM_Dedupetools_ExtensionUtil as E;

class CRM_Dedupetools_BAO_MergeHandler {

  /**
   * Various dedupe data as passed in from core in a mystical ugly format.
   *
   * @var array
   */
  protected $dedupeData = [];

  /**
   * Location blocks as calculated by the merge code & passed in alterLocationMergeData.
   *
   * @var array
   */
  protected $locationBlocks = [];

  /**
   * Resolutions to resolvable email conflicts.
   *
   * @var array
   */
  protected $locationConflictResolutions = [];

  /**
   * Contact ID to retain.
   *
   * @var int
   */
  protected $mainID;

  /**
   * Contact ID to be merged and deleted.
   *
   * @var int
   */
  protected $otherID;

  /**
   * Merge context.
   *
   * This comes from the core deduper and is generally form or batch.
   *
   * @var string
   */
  protected $context;

  /**
   * @var array
   */
  protected $emailConflicts;

  /**
   * Location blocks that should be deleted on merge.
   *
   * @var array
   */
  protected $locationBlocksToDelete = [];

  /**
   * Getter for dedupe Data.
   *
   * @return array
   */
  public function getDedupeData(): array {
    return $this->dedupeData;
  }

  /**
   * Setter for dedupe Data.
   *
   * @param array $dedupeData
   */
  public function setDedupeData(array $dedupeData) {
    $this->dedupeData = $dedupeData;
  }

  /**
   * Getter for main ID.
   *
   * @return mixed
   */
  public function getMainID() {
    return $this->mainID;
  }

  /**
   * Setter for main ID.
   *
   * @param mixed $mainID
   */
  public function setMainID($mainID) {
    $this->mainID = $mainID;
  }

  /**
   * Getter for other ID.
   *
   * @return mixed
   */
  public function getOtherID() {
    return $this->otherID;
  }

  /**
   * Setter for other ID.
   *
   * @param mixed $otherID
   */
  public function setOtherID($otherID) {
    $this->otherID = $otherID;
  }

  /**
   * Getter for context.
   *
   * @return mixed
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * Setter for context.
   *
   * @param mixed $context
   */
  public function setContext($context) {
    $this->context = $context;
  }

  /**
   * Get the fields that make up the name of an individual.
   *
   * @return array
   */
  public function getIndividualNameFields():array {
    return ['first_name', 'last_name', 'middle_name'];
  }

  /**
   * Get the fields that make up the name of an individual.
   *
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return array
   */
  public function getIndividualNameFieldValues($isForContactToBeKept):array {
    $return = [];
    foreach ($this->getIndividualNameFields() as $fieldName) {
      $return[$fieldName] = $this->getValueForField($fieldName, $isForContactToBeKept);
    }
    return $return ;
  }

  /**
   * Get the value for the given field.
   *
   * @param string $fieldName
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return mixed
   */
  public function getValueForField($fieldName, $isForContactToBeKept) {
    $contactDetail = $isForContactToBeKept ? $this->dedupeData['migration_info']['main_details'] : $this->dedupeData['migration_info']['other_details'];
    return $contactDetail[$fieldName];
  }

  /**
   * Is there a conflict in a field used to name an individual.
   *
   * @return bool
   */
  public function hasIndividualNameFieldConflict():bool {
    foreach ($this->getIndividualNameFields() as $nameField) {
      if ($this->isFieldInConflict($nameField)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array
   */
  public function getLocationBlocks(): array {
    $blocks = $this->locationBlocks;
    foreach ($blocks as $entity => $entityBlocks) {
      if (isset($this->locationBlocksToDelete[$entity])) {
        foreach ($this->locationBlocksToDelete[$entity] as $id) {
          if (isset($blocks[$entity]['update'][$id])) {
            $blocks[$entity]['delete'][$id] = $blocks[$entity]['update'][$id];
            unset($blocks[$entity]['update'][$id]);
          }
        }
      }
    }
    return $blocks;
  }

  /**
   * @param array $locationBlocks
   */
  public function setLocationBlocks(array $locationBlocks) {
    $this->locationBlocks = $locationBlocks;
  }


  /**
   * @return array
   */
  public function getLocationBlocksToDelete(): array {
    return $this->locationBlocksToDelete;
  }

  /**
   * @param array $locationBlocksToDelete
   */
  public function setLocationBlocksToDelete(array $locationBlocksToDelete) {
    $this->locationBlocksToDelete = $locationBlocksToDelete;
  }

  /**
   * CRM_Dedupetools_BAO_MergeHandler constructor.
   *
   * @param array $dedupeData
   *   Various dedupe data as passed in from core in a mystical ugly format.
   * @param int $mainID
   *   Contact ID to retain
   * @param $otherID
   *  Contact ID to be merged and deleted.
   * @param string $context
   *  Merge context passed in from core -usually form or batch.
   */
  public function __construct($dedupeData, $mainID, $otherID, $context) {
    $this->setDedupeData($dedupeData);
    $this->setMainID($mainID);
    $this->setOtherID($otherID);
    $this->setContext($context);
  }

  /**
   * Resolve merge.
   */
  public function resolve() {
    // @todo we'll build out how we manage resolvers later.
    //  Ideally we will try to make it align as much as we can
    // with https://github.com/systopia/de.systopia.xdedupe/tree/master/CRM/Xdedupe/Resolver
    // There is a fundamental difference in that his resolvers run BEFORE a merge not in the hook
    // so they do updates prior to a merge attempt. Ours are running as a merge hook and alter
    // already-determined conflicts.
    $resolver = new CRM_Dedupetools_BAO_Resolver_BooleanYesResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Dedupetools_BAO_Resolver_UninformativeCharactersResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Dedupetools_BAO_Resolver_MisplacedNameResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Dedupetools_BAO_Resolver_InitialResolver($this);
    $resolver->resolveConflicts();
  }

  /**
   * Resolve locations.
   *
   * The hook to resolve locations takes place later on in the process.
   */
  public function resolveLocations() {
    // tbc
  }

  /**
   * Get fields in conflict.
   *
   * @return array of keys of conflicted fields.
   */
  public function getFieldsInConflict():array {
    $fields = [];
    foreach (array_keys($this->dedupeData['fields_in_conflict']) as $key) {
      $fields[] = str_replace('move_', '', $key);
    }
    return $fields;
  }

  /**
   * Is there a conflict on the specified field.
   *
   * @param string $fieldName
   *
   * @return bool
   */
  public function isFieldInConflict($fieldName):bool {
    $conflictFields = $this->getFieldsInConflict();
    return in_array($fieldName, $conflictFields, TRUE);
  }

  /**
   * Resolve conflict on field using the specified value.
   *
   * @param string $fieldName
   * @param mixed $value
   */
  public function setResolvedValue($fieldName, $value) {
    $moveField = 'move_' . $fieldName;
    unset($this->dedupeData['fields_in_conflict'][$moveField]);
    $this->setValue($fieldName, $value);
  }

  /**
   * Resolve conflict on field using the specified value.
   *
   * @param string $fieldName
   * @param string $location
   * @param string $block
   * @param string $value
   */
  public function setResolvedLocationValue($fieldName, $location, $block, $value) {
    unset($this->emailConflicts[$fieldName]);
    $this->locationConflictResolutions[$location][$block][$fieldName] = $value;
    if (empty($this->emailConflicts[$block])) {
      $this->resolveConflictsOnLocationBlock($location, $block);
    }
  }

  /**
   * Get the specified block.
   *
   * @param string $location
   * @param string $block
   * @param int $isForContactToBeKept
   *
   * @return array
   */
  public function getLocationBlock($location, $block, $isForContactToBeKept):array {
    $contactString = $isForContactToBeKept ? 'main_details' : 'other_details';
    return $this->dedupeData['migration_info'][$contactString]['location_blocks'][$location][$block];
  }

  /**
   * Get the specified value from the specified block.
   *
   * @param string $location
   * @param string $block
   * @param int $isForContactToBeKept
   * @param string $field
   *
   * @return mixed
   */
  public function getLocationBlockValue($location, $block, $isForContactToBeKept, $field) {
    return $this->getLocationBlock($location, $block, $isForContactToBeKept)[$field];
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @param int $emailBlockNumber
   *
   * @return array
   *   Conflicts in emails.
   */
  public function getEmailConflicts($emailBlockNumber):array {
    if (isset($this->emailConflicts[$emailBlockNumber])) {
      return $this->emailConflicts[$emailBlockNumber];
    }
    $mainContactEmail = $this->dedupeData['migration_info']['main_details']['location_blocks']['email'][$emailBlockNumber];
    $otherContactEmail = $this->dedupeData['migration_info']['other_details']['location_blocks']['email'][$emailBlockNumber];
    $this->emailConflicts = [];
    // As defined in CRM_Dedupe_Merger::ignoredFields + display which is for the form layer.
    $keysToIgnore = [
      'id',
      'is_primary',
      'is_billing',
      'manual_geo_code',
      'contact_id',
      'reset_date',
      'hold_date',
      'display',
    ];
    foreach ($otherContactEmail as $field => $value) {
      if (
      isset($mainContactEmail[$field])
      && $mainContactEmail[$field] !== $value
      && !in_array($field, $keysToIgnore, TRUE) ) {
        $this->emailConflicts[$field] = $value;
      }
    }
    return $this->emailConflicts;
  }

  /**
   * Set the location address from the other contact as the one to keep.
   *
   * This mimics the 'magic values' that are set on the form when the user chooses to overwrite the main
   * contact's location block with the data from the other contact.
   *
   * @param string $location
   * @param string $block
   */
  public function setLocationAddressFromOtherContactToOverwriteMainContact($location, $block) {
    $this->dedupeData['migration_info']['location_blocks'][$location][$block]['operation'] = 2;
    $this->dedupeData['migration_info']['location_blocks'][$location][$block]['mainContactBlockId'] = $this->getLocationBlock($location, $block, TRUE)['id'];
  }

  /**
   * Is the merge handle handling conflict resolution for the given entity.
   *
   * @param string $locationEntity
   *
   * @return bool
   */
  public function isResolvingLocationConflictFor($locationEntity):bool {
    return !empty($this->locationConflictResolutions[$locationEntity]);
  }

  /**
   * Handle location block conflict resolution.
   *
   * @param string $location
   * @param string $block
   */
  protected function resolveConflictsOnLocationBlock($location, $block) {
    $mainContactValuesToKeep = [];
    $otherContactValuesToKeep = [];
    foreach ($this->locationConflictResolutions[$location][$block] as $fieldName => $value) {
      if ($this->getLocationBlockValue($location, $block, FALSE, $fieldName) == $value) {
        $otherContactValuesToKeep[$fieldName] = $value;
      }
      else {
        $mainContactValuesToKeep[$fieldName] = $value;
      }
    }
    if (!empty($otherContactValuesToKeep)) {
      // We want to keep at least one value from the other contact so set it to override.
      $this->setLocationAddressFromOtherContactToOverwriteMainContact($location, $block);
      if (!empty($mainContactValuesToKeep)) {
        // We need to ensure this value is not lost - do something.
      }
    }
    else {
      $this->locationBlocksToDelete[$location][$block] = $this->getLocationBlockValue($location, $block, FALSE, 'id');
    }
    unset($this->dedupeData['fields_in_conflict']['move_location_' . $location . '_' . $block]);
  }

  /**
   * Set the specified value as the one to use during merge.
   *
   * Note that if this resolves a conflict setResolvedValue should be used.
   *
   * @param string $fieldName
   * @param mixed $value
   * @param $isForContactToBeKept
   */
  public function setContactValue(string $fieldName, $value, $isForContactToBeKept) {
    $moveField = 'move_' . $fieldName;
    $contactField = $isForContactToBeKept ? 'main' : 'other';
    $otherContactField = $isForContactToBeKept ? 'other' : 'main';
    $this->dedupeData['migration_info']['rows'][$moveField][$contactField] = $value;
    $this->dedupeData['migration_info'][$contactField . '_details'][$fieldName] = $value;

    if ($value === $this->dedupeData['migration_info']['rows'][$moveField][$otherContactField]) {
      $this->setResolvedValue($fieldName, $value);
    }
  }

  /**
   * Set the specified value as the one to use during merge.
   *
   * Note that if this resolves a conflict setResolvedValue should be used.
   *
   * @param string $fieldName
   * @param mixed $value
   */
  public function setValue(string $fieldName, $value) {
    $moveField = 'move_' . $fieldName;
    $this->dedupeData['migration_info'][$moveField] = $value;
    $this->dedupeData['migration_info']['rows'][$moveField]['other'] = $value;
  }
}

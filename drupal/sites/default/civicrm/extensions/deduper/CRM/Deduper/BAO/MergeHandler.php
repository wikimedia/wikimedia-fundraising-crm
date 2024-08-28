<?php

use CRM_Deduper_ExtensionUtil as E;

class CRM_Deduper_BAO_MergeHandler {

  /**
   * Various dedupe data as passed in from core in a mystical ugly format.
   *
   * @var array
   */
  protected array $dedupeData = [];

  /**
   * Location blocks as calculated by the merge code & passed in alterLocationMergeData.
   *
   * @var array
   */
  protected array $locationBlocks = [];

  /**
   * Resolutions to resolvable email conflicts.
   *
   * @var array
   */
  protected array $locationConflictResolutions = [];

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
   * Is the dedupe in safe mode.
   *
   * @var bool
   */
  protected $safeMode;

  /**
   * @return bool
   */
  public function isSafeMode(): bool {
    return $this->safeMode;
  }

  /**
   * @param bool $safeMode
   */
  public function setSafeMode(bool $safeMode) {
    $this->safeMode = $safeMode;
  }

  /**
   * Details of email conflicts including:
   *  - fields
   *  - to_keep
   *  - to_remove
   *
   * @var array
   */
  protected array $emailConflictDetails = [];

  /**
   * Details of address conflicts including:
   *  - fields
   *  - to_keep
   *  - to_remove
   *
   * @var array
   */
  protected array $addressConflictDetails = [];

  /**
   * Details of phone conflicts including:
   *  - fields
   *  - to_keep
   *  - to_remove
   *
   * @var array
   */
  protected array $phoneConflictDetails = [];

  /**
   * Location blocks that should be deleted on merge.
   *
   * @var array
   */
  protected array $locationBlocksToDelete = [];

  /**
   * Temporary stash of settings.
   *
   * @var array
   */
  protected array $settings = [];

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
   * Helper for getting settings.
   *
   * This doesn't do much but it saves falling into questions as to whether a property
   * would be faster than the cached settings.get call.
   *
   * @param string $setting
   *
   * @return mixed
   */
  public function getSetting($setting) {
    if (!isset($this->settings[$setting])) {
      $this->settings[$setting] = \Civi::settings()->get($setting);
    }
    return $this->settings[$setting];
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
   * @return string
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
    return ['first_name', 'last_name', 'middle_name', 'nick_name'];
  }

  /**
   * Get the fields that make up the name of an Organization.
   *
   * @return array
   */
  public function getOrganizationNameFields():array {
    return ['organization_name'];
  }

  /**
   * Get the fields that make up the name of a Household.
   *
   * @return array
   */
  public function getHouseholdNameFields():array {
    return ['household_name'];
  }

  /**
   * Get the fields that make up the name of a Contact.
   *
   * @return array
   */
  public function getNameFields():array {
    return array_merge($this->getIndividualNameFields(), $this->getOrganizationNameFields(), $this->getHouseholdNameFields());
  }

  /**
   * Get the fields that make up the name of an individual.
   *
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return array
   */
  public function getIndividualNameFieldValues(bool $isForContactToBeKept):array {
    $return = [];
    foreach ($this->getIndividualNameFields() as $fieldName) {
      $return[$fieldName] = $this->getValueForField($fieldName, $isForContactToBeKept);
    }
    return $return;
  }

  /**
   * Get the address blocks for the contact.
   *
   * @param bool $isForContactToBeKept
   *
   * @return array
   */
  public function getAddresses(bool $isForContactToBeKept):array {
    if ($isForContactToBeKept) {
      return $this->dedupeData['migration_info']['main_details']['location_blocks']['address'];
    }
    return $this->dedupeData['migration_info']['other_details']['location_blocks']['address'];
  }

  /**
   * Get the location blocks for the contact for the given entity.
   *
   * @param string[address|phone|email] $entity
   * @param bool $isForContactToBeKept
   *
   * @return array
   */
  public function getLocationEntities($entity, $isForContactToBeKept):array {
    if ($isForContactToBeKept) {
      return $this->dedupeData['migration_info']['main_details']['location_blocks'][$entity];
    }
    return $this->dedupeData['migration_info']['other_details']['location_blocks'][$entity];
  }

  /**
   * Get the indexed address block for the contact.
   *
   * @param bool $isForContactToBeKept
   *
   * @param int $blockNumber
   *
   * @return array
   */
  public function getAddressBlock(bool $isForContactToBeKept, int $blockNumber):array {
    if ($isForContactToBeKept) {
      $otherContactAddress = $this->getAddresses(FALSE)[$blockNumber];
      foreach ($this->getAddresses(TRUE) as $address) {
        if (((int) $address['location_type_id']) === ((int) $otherContactAddress['location_type_id'])) {
          return $address;
        }
      }
    }
    else {
      return $this->getAddresses(FALSE)[$blockNumber];
    }
  }

  /**
   * Get the fields that make up the name of a contact.
   *
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return array
   */
  public function getNameFieldValues(bool $isForContactToBeKept):array {
    $return = [];
    foreach ($this->getNameFields() as $fieldName) {
      $return[$fieldName] = $this->getValueForField($fieldName, $isForContactToBeKept);
    }
    return $return;
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
  public function getValueForField(string $fieldName, bool $isForContactToBeKept) {
    if (strpos($fieldName, 'custom_') !== 0) {
      $contactDetail = $isForContactToBeKept ? $this->dedupeData['migration_info']['main_details'] : $this->dedupeData['migration_info']['other_details'];
      $value = $contactDetail[$fieldName] ?? NULL;
    }
    // You are now entering hell. The information you want is buried... somewhere.
    elseif (!$isForContactToBeKept) {
      // This is what would be 'just used' if we unset the conflict & leave 'move_custom_x' in the array
      // so if should be safe-ish.
      $value = $this->dedupeData['migration_info']['move_' . $fieldName];
    }
    else {
      // Honestly let's try passing back this formatted value .... because it IS deformatted at the other end.
      // We relying on unit tests & magic here.
      $value = $this->dedupeData['migration_info']['rows']['move_' . $fieldName]['main'];
    }
    if (is_string($value)) {
      return trim($value);
    }
    return $value;
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
   * Is there a conflict in a field used to name an Organization.
   *
   * @return bool
   */
  public function hasOrganizationNameFieldConflict():bool {
    return $this->isFieldInConflict('organization_name');
  }

  /**
   * Is there a conflict in a field used to name a household.
   *
   * @return bool
   */
  public function hasHouseholdNameFieldConflict():bool {
    return $this->isFieldInConflict('household_name');
  }

  /**
   * Is there a conflict in a name field.
   *
   * @return bool
   */
  public function hasNameFieldConflict(): bool {
    return $this->hasOrganizationNameFieldConflict() || $this->hasIndividualNameFieldConflict() || $this->hasHouseholdNameFieldConflict();
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
   * CRM_Deduper_BAO_MergeHandler constructor.
   *
   * @param array $dedupeData
   *   Various dedupe data as passed in from core in a mystical ugly format.
   * @param int $mainID
   *   Contact ID to retain
   * @param $otherID
   *  Contact ID to be merged and deleted.
   * @param string $context
   *  Merge context passed in from core -usually form or batch.
   * @param bool $isSafeMode
   */
  public function __construct(array $dedupeData, int $mainID, int $otherID, string $context, bool $isSafeMode) {
    $this->setDedupeData($dedupeData);
    $this->setMainID($mainID);
    $this->setOtherID($otherID);
    $this->setContext($context);
    $this->setSafeMode($isSafeMode);
  }

  /**
   * Resolve merge.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolve() {
    // @todo we'll build out how we manage resolvers later.
    //  Ideally we will try to make it align as much as we can
    // with https://github.com/systopia/de.systopia.xdedupe/tree/master/CRM/Xdedupe/Resolver
    // There is a fundamental difference in that his resolvers run BEFORE a merge not in the hook
    // so they do updates prior to a merge attempt. Ours are running as a merge hook and alter
    // already-determined conflicts.
    $resolver = new CRM_Deduper_BAO_Resolver_SkippedFieldsResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_GreetingResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_BooleanYesResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_EquivalentAddressResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_UninformativeCharactersResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_CasingResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_DiacriticResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_SillyNameResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_EquivalentNameResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_MisplacedNameResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_InitialResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_SubsetNameResolver($this);
    $resolver->resolveConflicts();

    $resolver = new CRM_Deduper_BAO_Resolver_PreferredContactLocationResolver($this);
    $resolver->resolveConflicts();

    // Let's do this one last - that way if someone wants to try to resolve names first they
    // can & then fall back on 'just use the value from the preferred contact.
    // @todo - should we make the resolvers sortable / re-order-able?
    $resolver = new CRM_Deduper_BAO_Resolver_PreferredContactFieldResolver($this);
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
    if ($this->isSafeMode()) {
      unset($this->dedupeData['fields_in_conflict'][$moveField]);
    }
    else {
      $this->dedupeData['fields_in_conflict'][$moveField] = $value;
    }
    $this->setValue($fieldName, $value);
  }

  /**
   * Resolve conflict on field using the specified value.
   *
   * @param string $fieldName
   * @param string $entity
   * @param int $block
   * @param string $value
   */
  public function setResolvedLocationValue(string $fieldName, string $entity, int $block, string $value) {
    $key = $entity . 'ConflictDetails';
    unset($this->$key[$block]['fields'][$fieldName]);
    $this->locationConflictResolutions[$entity][$block][$fieldName] = $value;
    if (empty($this->$key[$block]['fields']) || array_keys($this->$key[$block]['fields']) === ['display']) {
      $this->resolveConflictsOnLocationBlock($entity, $block);
    }
  }

  /**
   * Update an resolved address value, resolving the entire address if it is no longer in conflict.
   *
   * @param string $fieldName
   * @param string $location
   * @param int $block
   * @param string $value
   */
  public function setResolvedAddressValue(string $fieldName, string $location, int $block, string $value) {
    $this->locationConflictResolutions[$location][$block][$fieldName] = $value;
    $mainBlock = &$this->dedupeData['migration_info']['main_details']['location_blocks']['address'][$block];
    $otherBlock = &$this->dedupeData['migration_info']['other_details']['location_blocks']['address'][$block];
    unset($this->addressConflictDetails[$block]['fields'][$fieldName]);

    if (!empty($this->addressConflictDetails[$block]['fields']['display'])) {
      $mainDisplay = CRM_Utils_Address::format(array_merge($mainBlock, [$fieldName => $value]));
      $otherDisplay = CRM_Utils_Address::format(array_merge($otherBlock, [$fieldName => $value]));
      if ($mainDisplay === $otherDisplay) {
        unset($this->addressConflictDetails[$block]['fields']['display']);
      }
    }

    if (empty($this->addressConflictDetails[$block]['fields'])) {
      $this->resolveConflictsOnLocationBlock($location, $block);
    }
  }

  /**
   * Assign location to a new available location and block so it is retained.
   *
   * @param string $locationEntity
   * @param int $block
   * @param bool $isContactToKeep
   *   Does the location being rehomed belong to the contact to keep.
   * @param bool|null $isPrimary
   *   If not null the primary will be forced to this.
   */
  public function relocateLocation(string $locationEntity, int $block, $isContactToKeep = FALSE, $isPrimary = NULL): void {
    $locationTypeID = $this->getNextAvailableLocationType($locationEntity);
    if ($isContactToKeep) {
      // The CiviCRM form has no way to do this - we are kind of tricking it into thinking it is dealing with another
      // address on the contact to be changed.
      $nextBlock = $this->getNextAvailableLocationBlock($locationEntity);
      // We add this block from the 'to keep contact' to the 'to remove contact' so it
      // will 'copied across' and updated with our new location_type_id & is_primary.
      $blockToKeep = $this->getLocationBlock($locationEntity, $block, TRUE);
      $blockToKeep['location_type_id'] = $locationTypeID;
      $this->dedupeData['migration_info']['other_details']['location_blocks'][$locationEntity][$nextBlock] = $blockToKeep;
      $this->dedupeData['migration_info']['move_location_' . $locationEntity . '_' . $nextBlock] = TRUE;
      $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$nextBlock] = [
        'locTypeId' => $locationTypeID,
        'operation' => 1,
        'set_other_primary' => FALSE,
        'is_relocated' => TRUE,
      ];
      // We later check this to see whether we should overwrite it in setLocationAddressFromOtherContactToOverwriteMainContact.
      $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block]['is_relocated'] = TRUE;
    }
    else {
      // This version is simple - we are just mimicing the forms instructions.
      $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block] = [
        'operation' => 1,
        'set_other_primary' => $isPrimary,
        'is_relocated' => TRUE,
        'locTypeId' => $locationTypeID,
      ];
    }
  }

  /**
   * Set primary location to that of the contact to be deleted
   * @param string $locationEntity
   * @param int $block
   */
  public function setPrimaryLocationToDeleteContact($locationEntity, $block) {
    $blockOnContactToKeep = $this->getLocationBlock($locationEntity, $block, TRUE);
    $blockToBePrimary = $this->getLocationBlock($locationEntity, $block, FALSE);
    if (!empty($blockOnContactToKeep) && $blockOnContactToKeep['is_primary'] && $this->isBlockEquivalent($locationEntity, $blockOnContactToKeep, $blockToBePrimary)
      && $blockOnContactToKeep['location_type_id'] === $blockToBePrimary['location_type_id']
    ) {
      // No action required - it already is the primary.
      return;
    }
    $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block]['set_other_primary'] = 1;
    $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block]['operation'] = 2;
    $this->dedupeData['migration_info']['move_location_' . $locationEntity . '_' . $block] = TRUE;

    foreach ($this->getLocationEntities($locationEntity, TRUE) as $blockNumber => $toKeepBlock) {
      if ($this->isBlockEquivalent($locationEntity, $toKeepBlock, $blockToBePrimary)) {
        $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block]['mainContactBlockId'] = $toKeepBlock['id'];
      }
    }
  }

  /**
   * Is the second block functionally the same as the second.
   *
   * For example if they both have the same phone number they are functionally
   * the same information.
   *
   * @param string[address|phone|email] $locationEntity
   * @param array $entity1
   * @param array $entity2
   *
   * @return bool
   */
  public function isBlockEquivalent($locationEntity, $entity1, $entity2) {
    return $locationEntity === 'phone' ? ($entity1['phone_numeric'] === $entity2['phone_numeric']) : ($entity1['display'] === $entity2['display']);
  }

  /**
   * Does this block hold unique information ot otherwise replicated in other blocks.
   *
   * @param string[address|phone|email] $locationEntity
   * @param array $entityToConsiderRehoming
   * @param int $blockNumber
   *
   * @return bool
   */
  public function isBlockUnique(string $locationEntity, array $entityToConsiderRehoming, int $blockNumber): bool {
    foreach ($this->getAllLocationBlocks($locationEntity) as $existingEntity) {
      if ($existingEntity['id'] !== $entityToConsiderRehoming['id'] && $this->isBlockEquivalent($locationEntity, $existingEntity, $entityToConsiderRehoming)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Get all blocks for the given location, from both contacts.
   *
   * @param string $locationEntity
   *
   * @return array
   */
  public function getAllLocationBlocks($locationEntity): array {
    $blocks = [];
    foreach ($this->dedupeData['migration_info']['main_details']['location_blocks'][$locationEntity] as $block => $detail) {
      $detail['block'] = $block;
      $blocks[] = $detail;
    }
    foreach ($this->dedupeData['migration_info']['other_details']['location_blocks'][$locationEntity] as $block => $detail) {
      $detail['block'] = $block;
      $blocks[] = $detail;
    }
    return $blocks;
  }

  public function getNextAvailableLocationBlock($locationEntity) {
    $blocksInUse = [];
    foreach (array_merge(
      array_keys($this->dedupeData['migration_info']['main_details']['location_blocks'][$locationEntity]),
      array_keys($this->dedupeData['migration_info']['other_details']['location_blocks'][$locationEntity])
    ) as $block) {
      $blocksInUse[$block] = $block;
    }
    return array_pop($blocksInUse) + 1;
  }

  /**
   * Get the specified block.
   *
   * @param string $location
   * @param int $block
   * @param bool $isForContactToBeKept
   *
   * @return array
   */
  public function getLocationBlock(string $location, int $block, bool $isForContactToBeKept):array {
    if ($isForContactToBeKept) {
      // Search for the relevant location type block on the main contact.
      $locationTypeID = (int) $this->dedupeData['migration_info']['other_details']['location_blocks'][$location][$block]['location_type_id'];
      foreach ($this->dedupeData['migration_info']['main_details']['location_blocks'][$location] as $mainContactLocation) {
        if ($locationTypeID === ((int) $mainContactLocation['location_type_id'])) {
          return $mainContactLocation;
        }
      }
    }
    else {
      return $this->dedupeData['migration_info']['other_details']['location_blocks'][$location][$block] ?? [];
    }
    return [];
  }

  /**
   * Get merge instructions.
   *
   * If we had a form the values on the form would dictate this but we
   * mimic those in this class to achieve the desired result.
   *
   * @param string $locationEntity
   * @param int $block
   *
   * @return mixed
   */
  public function getMergeInstructionForBlock(string $locationEntity, int $block) {
    return $this->dedupeData['migration_info']['location_blocks'][$locationEntity][$block];
  }

  /**
   * Has the block been marked for relocation.
   *
   * @param string $locationEntity
   * @param int $block
   *
   * @return false|mixed
   */
  public function isRelocated(string $locationEntity, int $block) {
    return $this->getMergeInstructionForBlock($locationEntity, $block)['is_relocated'] ?? FALSE;
  }

  /**
   * Get the specified value from the specified block.
   *
   * @param string $location
   * @param int $block
   * @param bool $isForContactToBeKept
   * @param string $field
   *
   * @return mixed
   */
  public function getLocationBlockValue(string $location, int $block, bool $isForContactToBeKept, string $field) {
    return $this->getLocationBlock($location, $block, $isForContactToBeKept)[$field] ?? NULL;
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @param int $emailBlockNumber
   *
   * @return array
   *   List of fields with conflicts against the email in the provided block number for the contact to delete..
   */
  public function getEmailConflicts(int $emailBlockNumber):array {
    return $this->getEmailConflictDetails($emailBlockNumber)['fields'];
  }

  /**
   * @param int $emailBlockNumber
   *
   * @return array
   */
  public function getEmailConflictDetails(int $emailBlockNumber): array {
    if (!isset($this->emailConflictDetails[$emailBlockNumber])) {
      $otherContactEmail = $this->getBlockForEntity('email', $emailBlockNumber, FALSE);
      $mainContactEmail = $this->getBlockForEntity('email', $emailBlockNumber, TRUE);
      $this->emailConflictDetails[$emailBlockNumber] = [];
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
          && !in_array($field, $keysToIgnore, TRUE)) {
          $this->emailConflictDetails[$emailBlockNumber]['fields'][$field] = $value;
          $this->emailConflictDetails[$emailBlockNumber]['to_keep'] = $mainContactEmail;
          $this->emailConflictDetails[$emailBlockNumber]['to_remove'] = $otherContactEmail;
        }
      }
    }
    return $this->emailConflictDetails[$emailBlockNumber];
  }

  /**
   * @param int $blockNumber
   *
   * @return array
   */
  public function getPhoneConflictDetails(int $blockNumber): array {
    if (!isset($this->phoneConflictDetails[$blockNumber])) {
      $mainContactEntity = $this->getBlockForEntity('phone', $blockNumber, TRUE);
      $otherContactEntity = $this->getBlockForEntity('phone', $blockNumber, FALSE);
      $this->phoneConflictDetails[$blockNumber] = [];
      // As defined in CRM_Dedupe_Merger::ignoredFields + display which is for the form layer.
      $keysToIgnore = [
        'id',
        'is_primary',
        'is_billing',
        'contact_id',
        'display',
      ];
      foreach ($otherContactEntity as $field => $value) {
        if (
          isset($mainContactEntity[$field])
          && $mainContactEntity[$field] !== $value
          && !in_array($field, $keysToIgnore, TRUE)) {
          $this->phoneConflictDetails[$blockNumber]['fields'][$field] = $value;
          $this->phoneConflictDetails[$blockNumber]['to_keep'] = $mainContactEntity;
          $this->phoneConflictDetails[$blockNumber]['to_remove'] = $otherContactEntity;
        }
      }
    }
    return $this->phoneConflictDetails[$blockNumber];
  }

  /**
   * Get conflicts on all address blocks.
   *
   * @return array
   */
  public function getAllAddressConflicts(): array {
    $conflicts = [];
    foreach ($this->getFieldsInConflict() as $conflictedField) {
      if (strpos($conflictedField, 'location_address') === 0) {
        $blockNumber = intval(str_replace('location_address_', '', $conflictedField));
        $conflicts[$blockNumber] = $this->getAddressConflicts($blockNumber);
      }
    }
    return $conflicts;
  }

  /**
   * Get conflicts on all address blocks.
   *
   * @param string $entity
   *
   * @return array
   */
  public function getAllConflictsForEntity(string $entity): array {
    $conflicts = [];
    foreach ($this->getFieldsInConflict() as $conflictedField) {
      if (strpos($conflictedField, 'location_' . $entity) === 0) {
        $blockNumber = (int) (str_replace('location_' . $entity . '_', '', $conflictedField));
        if ($entity === 'email') {
          $blockConflicts = $this->getEmailConflictDetails($blockNumber);
        }
        if ($entity === 'address') {
          $blockConflicts = $this->getAddressConflictDetails($blockNumber);
        }
        if ($entity === 'phone') {
          $blockConflicts = $this->getPhoneConflictDetails($blockNumber);
        }
        if (!empty($blockConflicts)) {
          $conflicts[$blockNumber] = $blockConflicts;
        }
      }
    }
    return $conflicts;
  }

  /**
   * Get conflicts for the address of the given block.
   *
   * @param int $blockNumber
   *
   * @return array
   *   Conflicts in addresses.
   */
  public function getAddressConflicts(int $blockNumber):array {
    return $this->getAddressConflictDetails($blockNumber)['fields'] ?? [];
  }

  /**
   * @param int $blockNumber
   *
   * @return array
   */
  public function getAddressConflictDetails(int $blockNumber): array {
    if (!isset($this->addressConflictDetails[$blockNumber])) {
      $mainContactAddress = $this->getBlockForEntity('address', $blockNumber, TRUE);
      $otherContactAddress = $this->getBlockForEntity('address', $blockNumber, FALSE);
      $this->addressConflictDetails[$blockNumber] = [];
      // As defined in CRM_Dedupe_Merger::ignoredFields + display which is for the form layer.
      $keysToIgnore = [
        'id',
        'is_primary',
        'is_billing',
        'manual_geo_code',
        'contact_id',
      ];
      foreach ($otherContactAddress as $field => $value) {
        if (
          isset($mainContactAddress[$field])
          && $mainContactAddress[$field] !== $value
          && !in_array($field, $keysToIgnore, TRUE)) {
          $this->addressConflictDetails[$blockNumber]['fields'][$field] = $value;
          $this->addressConflictDetails[$blockNumber]['to_keep'] = $mainContactAddress;
          $this->addressConflictDetails[$blockNumber]['to_remove'] = $otherContactAddress;
        }
      }
    }
    return $this->addressConflictDetails[$blockNumber];
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
   * Is the second block functionally the same as the second.
   *
   * For example if they both have the same phone number they are functionally
   * the same information.
   *
   * @param string[address|phone|email] $locationEntity
   * @param int $block
   */
  public function setDoNotMoveBlock(string $locationEntity, int $block) {
    unset($this->dedupeData['migration_info']['move_location_' . $locationEntity . '_' . $block]);
  }

  /**
   * Set a field to be left unmoved when the contact pair is deduped.
   *
   * @param string $fieldName
   */
  public function setDoNotMoveField(string $fieldName): void {
    if (array_key_exists('move_' . $fieldName, $this->dedupeData['fields_in_conflict'])) {
      unset($this->dedupeData['fields_in_conflict']['move_' . $fieldName]);
    }
    if (array_key_exists('move_' . $fieldName, $this->dedupeData['migration_info'])) {
      unset($this->dedupeData['migration_info']['move_' . $fieldName]);
    }
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
   * @param int $block
   */
  protected function resolveConflictsOnLocationBlock(string $location, int $block): void {
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
    if (!$this->isRelocated($location, $block)) {
      if (!empty($otherContactValuesToKeep)) {
        // We want to keep at least one value from the other contact so set it to override.
        $this->setLocationAddressFromOtherContactToOverwriteMainContact($location, $block);
      }
      else {
        if (!empty($mainContactValuesToKeep)) {
          // Do not copy the value over from the other contact.
          unset($this->dedupeData['migration_info']['move_location_' . $location . '_' . $block]);
        }
        // This whole locationBlocksToDelete idea is actually not being pursued. Leaving for now but
        // I think it only 'sends a message' to tell wmf_civicrm.module not to handle so it can go once
        // we fully remove from there.
        $this->locationBlocksToDelete[$location][$block] = $this->getLocationBlockValue($location, $block, FALSE, 'id');
      }
    }
    unset($this->dedupeData['fields_in_conflict']['move_location_' . $location . '_' . $block]);
  }

  /**
   * Set the specified value as the one to use during merge.
   *
   * If by doing this the fields then match then the conflict will be marked as resolved.
   *
   * Otherwise this is basically just a 'working copy' of the information, which
   * might help a later resolver reach resolution.
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

    if (!isset($this->dedupeData['migration_info']['rows'][$moveField][$otherContactField])
    || ($value === $this->dedupeData['migration_info']['rows'][$moveField][$otherContactField])) {
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

  /**
   * @throws \CRM_Core_Exception
   */
  public function getPreferredContact(): int {
    $preferredContact = new CRM_Deduper_BAO_PreferredContact($this->mainID, $this->otherID);
    return $preferredContact->getPreferredContactID();
  }

  /**
   * @param string $fieldName
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public function getPreferredContactValue($fieldName) {
    return $this->getValueForField($fieldName, $this->isContactToKeepPreferred());
  }

  /**
   * Get the array of fields for which the preferred contact is the best resolution.
   *
   * @return array
   */
  public function getFieldsToResolveOnPreferredContact(): array {
    $conflictedFields = $this->getFieldsInConflict();
    if (!$this->isSafeMode()) {
      // In aggressive mode we are resolving all remaining fields.
      return $conflictedFields;
    }
    $fieldsToResolve = (array) $this->getSetting('deduper_resolver_field_prefer_preferred_contact');
    return array_intersect($fieldsToResolve, $conflictedFields);
  }

  /**
   * Is the contact to be kept the preferred contact.
   *
   * @return bool
   *
   * @throws \CRM_Core_Exception
   */
  public function isContactToKeepPreferred(): bool {
    return $this->getPreferredContact() === $this->mainID;
  }

  /**
   * Get all locations in use for this entity.
   *
   * @param string $entity
   *
   * @return array
   *   Array of location ids in use by at least one of the 2 contacts.
   */
  protected function getLocationsInUse($entity): array {
    $locationsInUse = [];
    foreach ($this->getAllLocationBlocks($entity) as $block) {
      $locationsInUse[$block['location_type_id']] = (int) $block['location_type_id'];
    }
    return $locationsInUse;
  }

  /**
   * Get next available location type.
   *
   * Find a location type not currently in user. Get the priority order from setting deduper_location_priority_order.
   *
   * @param string[address|email|phone|website|im] $locationEntity
   *
   * @return int
   */
  protected function getNextAvailableLocationType($locationEntity): int {
    $locationsToChooseFrom = Civi::settings()->get('deduper_location_priority_order');
    if (!is_array($locationsToChooseFrom)) {
      // I'm having some trouble with this setting on save & retrieve as an array - for now, handle here.
      // I'd rather dig further after the next civi update.
      $locationsToChooseFrom = explode(CRM_Core_DAO::VALUE_SEPARATOR, $locationsToChooseFrom);
    }
    $availableOrderedLocations = array_diff($locationsToChooseFrom, $this->getLocationsInUse($locationEntity));
    return (int) (reset($availableOrderedLocations) ?: 0);
  }

  /**
   * @param string $entity
   * @param int $blockNumber
   * @param bool $isContactToKeep
   *
   * @return mixed
   */
  public function getBlockForEntity(string $entity, int $blockNumber, bool $isContactToKeep) {
    if (!$isContactToKeep) {
      return $this->dedupeData['migration_info']['other_details']['location_blocks'][$entity][$blockNumber];
    }
    $otherContactEntity = $this->getBlockForEntity($entity, $blockNumber, FALSE);
    foreach ($this->dedupeData['migration_info']['main_details']['location_blocks'][$entity] as $details) {
      // The block number may not be the same, so we need to find the email on the main.
      // It is possible they have more than one of the location type, so get the first one
      // which does not have the same email.
      if ((int) $details['location_type_id'] === (int) $otherContactEntity['location_type_id']
        && (
          ($entity === 'phone' && $details['phone'] !== $otherContactEntity['phone'])
          || $details['display'] !== $otherContactEntity['display']
        )
      ) {
        return $details;
      }
    }
    return [];
  }

}

<?php

use CRM_Deduper_ExtensionUtil as E;

abstract class CRM_Deduper_BAO_Resolver {

  abstract public function resolveConflicts();
  /**
   * Object to prover merge handling.
   *
   * @var \CRM_Deduper_BAO_MergeHandler
   */
  protected $mergeHandler;

  /**
   * CRM_Deduper_BAO_Resolver constructor.
   *
   * @param CRM_Deduper_BAO_MergeHandler $mergeHandler
   */
  public function __construct($mergeHandler) {
    $this->mergeHandler = $mergeHandler;
  }

  /**
   * Get fields currently in conflict.
   *
   * @return array
   */
  protected function getFieldsInConflict(): array {
    return $this->mergeHandler->getFieldsInConflict();
  }

  /**
   * Set the given value as the value to resolve the conflict with.
   *
   * @param string $fieldName
   * @param mixed $value
   */
  protected function setResolvedValue($fieldName, $value) {
    $this->mergeHandler->setResolvedValue($fieldName, $value);
  }

  /**
   * Set the specified value as the one to use during merge.
   *
   * Note that if this resolves a conflict then the conflict
   * will be removed.
   *
   * @param string $fieldName
   * @param mixed $value
   * @param bool $isContactToKeep
   */
  protected function setContactValue($fieldName, $value, $isContactToKeep) {
    $this->mergeHandler->setContactValue($fieldName, $value, $isContactToKeep);
  }

  /**
   * Set the specified value as the one to use during merge.
   *
   * Note that if this resolves a conflict setResolvedValue should be used.
   *
   * @param string $fieldName
   * @param mixed $value
   */
  protected function setValue($fieldName, $value) {
    $this->mergeHandler->setValue($fieldName, $value);
  }

  /**
   * Set the given value as the value to resolve the conflict with.
   *
   * @param string $fieldName
   * @param string[email|address|phone|website|im] $location
   * @param int $block
   * @param mixed $value
   */
  protected function setResolvedLocationValue(string $fieldName, string $location, int $block, $value): void {
    $this->mergeHandler->setResolvedLocationValue($fieldName, $location, $block, $value);
  }

  /**
   * Designate the primary location o the contact to be deleted as the one to be the primary.
   *
   * @param string $locationEntity
   * @param int $block
   */
  public function setPrimaryLocationToDeleteContact($locationEntity, $block) {
    $this->mergeHandler->setPrimaryLocationToDeleteContact($locationEntity, $block);
  }

  /**
   * Has the block been marked for relocation.
   *
   * @param string $locationEntity
   * @param int $block
   *
   * @return false|mixed
   */
  public function isRelocated(string $locationEntity, int $block): bool {
    return $this->mergeHandler->getMergeInstructionForBlock($locationEntity, $block)['is_relocated'] ?? FALSE;
  }

  /**
   * Does this block hold unique information to otherwise replicated in other blocks.
   *
   * @param string[address|phone|email] $locationEntity
   * @param array $entityToConsiderRehoming
   * @param int $blockNumber
   *
   * @return bool
   */
  protected function isBlockUnique(string $locationEntity, array $entityToConsiderRehoming, int $blockNumber): bool {
    return $this->mergeHandler->isBlockUnique($locationEntity, $entityToConsiderRehoming, $blockNumber);
  }

  /**
   * Set the given value as the value to resolve the conflict with.
   *
   * @param string $fieldName
   * @param string $location
   * @param int $block
   * @param mixed $value
   */
  protected function setResolvedAddressValue($fieldName, $location, int $block, $value) {
    $this->mergeHandler->setResolvedAddressValue($fieldName, $location, $block, $value);
  }

  /**
   * Assign location to a new available location and block so it is retained.
   *
   * @param string $location
   * @param int $block
   * @param bool $isContactToKeep
   *   Does the location belong to the contact to keep.
   * @param bool|null $isPrimary
   *   If not null the primary will be forced to this.
   */
  public function relocateLocation($location, $block, $isContactToKeep, $isPrimary = NULL) {
    $this->mergeHandler->relocateLocation($location, $block, $isContactToKeep, $isPrimary);
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @param int $emailBlockNumber
   *
   * @return array
   */
  protected function getEmailConflicts(int $emailBlockNumber):array {
    return $this->mergeHandler->getEmailConflicts($emailBlockNumber);
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @return array
   */
  protected function getAllConflictsForEntity($entity):array {
    return $this->mergeHandler->getAllConflictsForEntity($entity);
  }

  /**
   * Get the location blocks for the contact for the given entity.
   *
   * @param $entity
   * @param bool $isForContactToBeKept
   *
   * @return array
   */
  protected function getLocationEntities($entity, $isForContactToBeKept):array {
    return $this->mergeHandler->getLocationEntities($entity, $isForContactToBeKept);
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @return array
   */
  protected function getAllAddressConflicts():array {
    return $this->mergeHandler->getAllAddressConflicts();
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
    return $this->mergeHandler->isBlockEquivalent($locationEntity, $entity1, $entity2);
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
    $this->mergeHandler->setDoNotMoveBlock($locationEntity, $block);
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @param $isForContactToBeKept
   * @param $blockNumber
   *
   * @return array
   */
  protected function getAddressBlock($isForContactToBeKept, $blockNumber):array {
    return $this->mergeHandler->getAddressBlock($isForContactToBeKept, $blockNumber);
  }

  /**
   * Get all blocks for the given location, from both contacts.
   *
   * @param string $locationEntity
   *
   * @return array
   */
  public function getAllLocationBlocks($locationEntity): array {
    return $this->mergeHandler->getAllLocationBlocks($locationEntity);
  }

  /**
   * Is there a conflict on the specified field.
   *
   * @param string $fieldName
   *
   * @return bool
   */
  protected function isFieldInConflict($fieldName):bool {
    return $this->mergeHandler->isFieldInConflict($fieldName);
  }

  /**
   * Is there a conflict in a field used to name an individual.
   */
  protected function hasIndividualNameFieldConflict():bool {
    return $this->mergeHandler->hasIndividualNameFieldConflict();
  }

  /**
   * Is there a conflict in a field used to name an individual.
   */
  protected function hasNameFieldConflict():bool {
    return $this->mergeHandler->hasNameFieldConflict();
  }

  /**
   * Get the fields that make up the name of an individual.
   *
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return array
   */
  protected function getIndividualNameFieldValues($isForContactToBeKept): array {
    return $this->mergeHandler->getIndividualNameFieldValues($isForContactToBeKept);
  }

  /**
   * Get the fields that make up the name of an individual.
   *
   * @param bool $isForContactToBeKept
   *   Is the value for the contact to be retained.
   *
   * @return array
   */
  protected function getNameFieldValues($isForContactToBeKept): array {
    return $this->mergeHandler->getNameFieldValues($isForContactToBeKept);
  }

  /**
   * @param string $fieldName
   * @param bool $isForContactToBeKept
   *
   * @return mixed|null
   */
  protected function getValueForField(string $fieldName, bool $isForContactToBeKept) {
    return $this->mergeHandler->getValueForField($fieldName, $isForContactToBeKept);
  }

  /**
   * Get the value for the given field for the preferred conflict, using rules.
   *
   * @param string $fieldName
   *
   * @return mixed
   *
   * @throws \CRM_Core_Exception
   */
  protected function getPreferredContactValue($fieldName) {
    return $this->mergeHandler->getPreferredContactValue($fieldName);
  }

  /**
   * Is the contact to be kept the preferred contact.
   *
   * @return bool
   *
   * @throws \CRM_Core_Exception
   */
  protected function isContactToKeepPreferred(): bool {
    return $this->mergeHandler->isContactToKeepPreferred();
  }

  /**
   * Get setting.
   *
   * @param string $setting
   *
   * @return string|int|array
   */
  protected function getSetting($setting) {
    return $this->mergeHandler->getSetting($setting);
  }

  /**
   * Get the array of fields for which the preferred contact's value should be preferred.
   *
   * @return array
   */
  protected function getFieldsToResolveOnPreferredContact(): array {
    return $this->mergeHandler->getFieldsToResolveOnPreferredContact();
  }
}

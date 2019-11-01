<?php

use CRM_Dedupetools_ExtensionUtil as E;

abstract class CRM_Dedupetools_BAO_Resolver {

  abstract public function resolveConflicts();
  /**
   * Object to prover merge handling.
   *
   * @var \CRM_Dedupetools_BAO_MergeHandler
   */
  protected $mergeHandler;

  /**
   * CRM_Dedupetools_BAO_Resolver constructor.
   *
   * @param CRM_Dedupetools_BAO_MergeHandler $mergeHandler
   */
  public function __construct($mergeHandler) {
    $this->mergeHandler = $mergeHandler;
  }

  /**
   * Get fields currently in conflict.
   *
   * @return array
   */
  protected function getFieldsInConflict() {
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
   * @param string $location
   * @param string $block
   * @param mixed $value
   */
  protected function setResolvedLocationValue($fieldName, $location, $block, $value) {
    $this->mergeHandler->setResolvedLocationValue($fieldName, $location, $block, $value);
  }

  /**
   * Get conflicts for the email address of the given block.
   *
   * @param int $emailBlockNumber
   *
   * @return array
   */
  protected function getEmailConflicts($emailBlockNumber):array {
    return $this->mergeHandler->getEmailConflicts($emailBlockNumber);
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

}

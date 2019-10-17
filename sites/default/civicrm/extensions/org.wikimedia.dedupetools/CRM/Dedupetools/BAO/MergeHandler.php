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
  }

  /**
   * Get fields in conflict.
   *
   * @return array of keys of conflicted fields.
   */
  public function getFieldsInConflict() {
    $fields = [];
    foreach (array_keys($this->dedupeData['fields_in_conflict']) as $key) {
      $fields[] = str_replace('move_', '', $key);
    }
    return $fields;
  }

  /**
   * Resolve conflict on field using the specified value.
   * @param string $fieldName
   * @param mixed $value
   */
  public function setResolvedValue($fieldName, $value) {
    $moveField = 'move_' . $fieldName;
    unset($this->dedupeData['fields_in_conflict'][$moveField]);
    $this->dedupeData['migration_info'][$moveField] = $value;
    $this->dedupeData['rows'][$moveField]['other'] = $value;
  }

}

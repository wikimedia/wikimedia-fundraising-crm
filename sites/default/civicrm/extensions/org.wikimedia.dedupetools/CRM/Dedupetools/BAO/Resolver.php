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

}

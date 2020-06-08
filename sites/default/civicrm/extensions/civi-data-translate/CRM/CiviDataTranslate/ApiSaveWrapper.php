<?php

/**
 * Collection of upgrade steps.
 */
class CRM_CiviDataTranslate_ApiSaveWrapper extends CRM_CiviDataTranslate_ApiUpdateWrapper{

  /**
   * Filter out any language specific fields.
   *
   * These are saved in the toApiOutput wrapper.
   *
   * @inheritdoc
   *
   * @param \Civi\Api4\Generic\DAOSaveAction $apiRequest
   */
  public function fromApiInput($apiRequest) {
    $apiRequest->setDefaults(array_diff_key($apiRequest->getDefaults(), $this->fields));
    return $apiRequest;
  }

}

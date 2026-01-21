<?php

use Civi\Api4\InvalidChecksum;
use CRM_ChecksumInvalidator_ExtensionUtil as E;

/**
 * InvalidChecksum.Clearexpired API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_invalid_checksum_Clearexpired_spec(&$spec) {
}

/**
 * InvalidChecksum.Clearexpired API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws CRM_Core_Exception
 */
function civicrm_api3_invalid_checksum_Clearexpired($params) {
  InvalidChecksum::delete(FALSE)
    ->addWhere('expiry', '<', 'now')
    ->execute()->count();
  return civicrm_api3_create_success();
}

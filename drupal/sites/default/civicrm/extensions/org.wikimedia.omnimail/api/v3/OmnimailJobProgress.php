<?php

/**
 * OmnimailJobProgress.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_omnimail_job_progress_create($params) {
  if (isset($params['retrieval_parameters']) && is_array($params['retrieval_parameters'])) {
    $params['retrieval_parameters'] = json_encode($params['retrieval_parameters']);
  }
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * OmnimailJobProgress.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_omnimail_job_progress_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * OmnimailJobProgress.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws CRM_Core_Exception
 */
function civicrm_api3_omnimail_job_progress_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

<?php

class CRM_MatchingGifts_Synchronizer {

  /**
   * @var CRM_MatchingGifts_ProviderInterface
   */
  protected $policyProvider;

  /**
   * @var int|null
   */
  protected $jobId;

  /**
   * Timestamp of current job start
   * @var int|null
   */
  protected $jobStart;

  public function __construct(CRM_MatchingGifts_ProviderInterface $policyProvider) {
    $this->policyProvider = $policyProvider;
  }

  /**
   * @param array $syncParams keys include 'batch', a mandatory integer that
   *  determines how many records to process, as well as any params that can
   *  be passed to CRM_MatchingGifts_ProviderInterface::getSearchResults
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public function synchronize(array $syncParams): array {
    $this->populateCurrentJobSettings();
    if (!$this->jobId) {
      // Starting a new sync
      $this->createNewJobSettings();
      $searchResults = $this->policyProvider->getSearchResults($syncParams);
      foreach ($searchResults as $companyId => $searchResult) {
        CRM_Core_DAO::executeQuery(
          "INSERT INTO civicrm_matching_gift_job_progress (job_id, company_id)
         VALUES (%1, %2)",
          [
            1 => [$this->jobId, 'Integer'],
            2 => [$companyId, 'String']
          ]
        );
      }
    }
    else {
      // Continuing a sync in progress
      $searchResults = CRM_Core_DAO::executeQuery(
        "SELECT company_id AS matching_gifts_provider_id
       FROM civicrm_matching_gift_job_progress
       WHERE job_id=%1
       AND processed=0",
        [1 => [$this->jobId, 'Integer']]
      )->fetchAll();
    }
    $policies = [];
    foreach ($searchResults as $searchResult) {
      $companyId = $searchResult['matching_gifts_provider_id'];
      $details = $this->policyProvider->getPolicyDetails($companyId);
      CRM_MatchingGifts_Synchronizer::addOrUpdatePolicy($details);
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_matching_gift_job_progress
       SET processed=1
       WHERE job_id=%1
       AND company_id=%2",
        [
          1 => [$this->jobId, 'Integer'],
          2 => [$companyId, 'String']
        ]
      );
      $policies[$companyId] = $details;
      if (count($policies) === (int)$syncParams['batch']) {
        break;
      }
    }
    if (count($policies) === count($searchResults)) {
      // Processed all of them
      $this->markCurrentJobDone();
    }
    return $policies;
  }

  protected function fullSettingName(string $setting): string {
    return CRM_MatchingGifts_ProviderFactory::fullSettingName(
      $setting,
      $this->policyProvider->getName()
    );
  }

  protected function populateCurrentJobSettings() {
    $settings = Civi::settings();
    $this->jobId = $settings->get($this->fullSettingName('current_job_id'));
    $this->jobStart = $settings->get($this->fullSettingName('current_job_start'));
  }

  protected function createNewJobSettings() {
    $id = (int)CRM_Core_DAO::singleValueQuery(
        'SELECT COALESCE(MAX(job_id), 0)
         FROM civicrm_matching_gift_job_progress'
      ) + 1;
    $start = time();
    $settings = Civi::settings();
    $settings->set($this->fullSettingName('current_job_id'), $id);
    $settings->set($this->fullSettingName('current_job_start'), $start);
    $this->jobId = $id;
    $this->jobStart = $start;
  }

  protected function markCurrentJobDone() {
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_matching_gift_job_progress
       WHERE job_id=%1',
      [1 => [$this->jobId, 'Integer']]
    );
    $settings = Civi::settings();
    $settings->set($this->fullSettingName('current_job_id'), NULL);
    $oldStart = $settings->get($this->fullSettingName('current_job_start'));
    $settings->set(
      $this->fullSettingName('last_updated'),
      (new DateTime('@' . $oldStart))->format('Y-m-d'));
    $settings->set($this->fullSettingName('current_job_start'), NULL);
  }

  /**
   * @param array $policyDetails should have keys for each of the custom fields
   *  in the matching_gift_policies group except for
   *  suppress_from_employer_field:
   *  matching_gifts_provider_id, matching_gifts_provider_info_url,
   *  name_from_matching_gift_db, guide_url, online_form_url
   *  minimum_gift_matched_usd, match_policy_last_updated, and subsidiaries
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected static function addOrUpdatePolicy(array $policyDetails) {
    // Search for an existing org WITH matching gift data using
    // $policyDetails['matching_gifts_provider_id'] matching
    // matching_gifts_provider_id
    // We pass the third param TRUE so it's already prefixed with "custom_"
    $providerCompanyIdFieldId = CRM_Core_BAO_CustomField::getCustomFieldID(
      'matching_gifts_provider_id', 'matching_gift_policies', TRUE
    );
    $orgContacts = civicrm_api3(
      'Contact', 'get', [
        'contact_type' => 'Organization',
        $providerCompanyIdFieldId => $policyDetails['matching_gifts_provider_id']
      ]
    );
    if ($orgContacts['count'] === 0) {
      // if not found, search for an existing org whose name matches
      // $policyDetails['name_from_matching_gift_db']
      $orgContacts = civicrm_api3(
        'Contact', 'get', [
          'organization_name' => $policyDetails['name_from_matching_gift_db'],
          'contact_type' => 'Organization'
        ]
      );
    }
    if ($orgContacts['count'] === 0) {
      // The nick_name field also might have a matching company name
      $orgContacts = civicrm_api3('Contact', 'get', [
        'nick_name' => $policyDetails['name_from_matching_gift_db'],
        'contact_type' => 'Organization'
        ]
      );
    }

    if ($orgContacts['count'] === 0) {
      // Base params for creating a new organizational contact
      $createParams = [
        'contact_type' => 'Organization',
        'contact_source' => 'Matching Gifts Extension',
        'organization_name' => $policyDetails['name_from_matching_gift_db'],
      ];
    } else {
      // Base params for updating an existing org contact
      $createParams = [
        'id' => array_keys($orgContacts['values'])[0],
      ];
    }
    $createParams = $createParams + self::getCustomFieldParams($policyDetails);
    civicrm_api3('Contact', 'Create', $createParams);
  }

  protected static function getCustomFieldParams(array $policyDetails): array {
    $params = [];
    foreach($policyDetails as $fieldName => $value) {
      $paramName = CRM_Core_BAO_CustomField::getCustomFieldID(
        $fieldName, 'matching_gift_policies', TRUE
      );
      $params[$paramName] = $value;
    }
    return $params;
  }

}

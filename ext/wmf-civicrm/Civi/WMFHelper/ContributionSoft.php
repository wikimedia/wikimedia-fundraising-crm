<?php

namespace Civi\WMFHelper;

class ContributionSoft {

  /**
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public static function getEmploymentSoftCreditTypes(): array {
    return self::getCachedTypes('wmf_civicrm_employer_soft_credit_types', ['workplace', 'matched_gift']);
  }

  /**
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public static function getDonorAdvisedFundSoftCreditTypes(): array {
    return self::getCachedTypes('wmf_civicrm_daf_soft_credit_types', ['donor-advised_fund']);
  }

  /**
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public static function getBankingInstitutionSoftCreditTypes(): array {
    return self::getCachedTypes('wmf_civicrm_banking_soft_credit_types', ['Banking Institution']);
  }

  /**
   * @param string $key
   * @param array $softCreditTypes
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private static function getCachedTypes(string $key, array $softCreditTypes): array {
    if (!\Civi::cache('metadata')->has($key)) {
      $types = \Civi\Api4\ContributionSoft::getFields(FALSE)
        ->setLoadOptions(['id', 'name'])
        ->addWhere('name', '=', 'soft_credit_type_id')
        ->execute()
        ->first()['options'];
      $employmentSoftCreditTypes = [];
      foreach ($types as $type) {
        if (in_array($type['name'], $softCreditTypes, TRUE)) {
          $employmentSoftCreditTypes[$type['name']] = (int) $type['id'];
        }
      }
      \Civi::cache('metadata')->set($key, $employmentSoftCreditTypes);
    }
    return \Civi::cache('metadata')->get($key);
  }

}

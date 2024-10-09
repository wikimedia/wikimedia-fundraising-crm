<?php

namespace Civi\WMFHelper;

class ContributionSoft {

  /**
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public static function getEmploymentSoftCreditTypes(): array {
    if (!\Civi::cache('metadata')->has('wmf_civicrm_employer_soft_credit_types')) {
      $types = \Civi\Api4\ContributionSoft::getFields(FALSE)
        ->setLoadOptions(['id', 'name'])
        ->addWhere('name', '=', 'soft_credit_type_id')
        ->execute()
        ->first()['options'];
      $employmentSoftCreditTypes = [];
      foreach ($types as $type) {
        if (in_array($type['name'], ['workplace', 'matched_gift'])) {
          $employmentSoftCreditTypes[$type['name']] = (int) $type['id'];
        }
      }
      \Civi::cache('metadata')
        ->set('wmf_civicrm_employer_soft_credit_types', $employmentSoftCreditTypes);
      return $employmentSoftCreditTypes;
    }
    return \Civi::cache('metadata')->get('wmf_civicrm_employer_soft_credit_types');
  }

}

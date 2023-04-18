<?php

namespace Civi\WMFHooks;

use Civi;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Api4\Contribution;

class ContributionSoft {

  /**
   * Create employer relationship for employer type soft credit.
   *
   * @param string $op
   * @param array $softCreditParams
   *
   * @throws \CRM_Core_Exception
   */
  public static function pre(string $op, array $softCreditParams) {
    if ($op === 'create' && in_array($softCreditParams['soft_credit_type_id'] ?? NULL, self::getEmploymentSoftCreditTypes(), TRUE)) {
      $contributionContact = Contribution::get(FALSE)
        ->addWhere('id', '=', $softCreditParams['contribution_id'])
        ->addSelect('contact_id', 'contact_id.contact_type', 'contact_id.organization_name', 'contact_id.employer_id', 'receive_date')
        ->execute()
        ->first();
      if (strtotime($contributionContact['receive_date']) < strtotime('3 months ago')) {
        // This is just a precaution against someone importing really old contributions
        // and reverting employer relationships to some previous point in time
        // The 3 months is just an arbitrary number that seemed safe & sensible to me.
        return;
      }

      $anonymousContactID = \Civi\WMFHelpers\Contact::getAnonymousContactID();

      $creditContact = Contact::get(FALSE)
        ->addWhere('id', '=', $softCreditParams['contact_id'])
        ->addSelect('contact_type', 'organization_name', 'employer_id', 'id')
        ->execute()
        ->first();
      if ($creditContact['contact_type'] === 'Individual'
        && $creditContact['id'] !== $anonymousContactID
        && $creditContact['employer_id'] !== $contributionContact['contact_id']) {
        Contact::update(FALSE)->setValues([
          'employer_id' => $contributionContact['contact_id'],
        ])->addWhere('id', '=', $creditContact['id'])->execute();
      }
      if ($creditContact['contact_type'] === 'Organization'
        && $contributionContact['contact_id'] !== $anonymousContactID
        && $contributionContact['contact_id.employer_id'] !== $creditContact['id']) {
        Contact::update(FALSE)->setValues([
          'employer_id' => $creditContact['id'],
        ])->addWhere('id', '=', $contributionContact['contact_id'])->execute();
      }
    }
  }

  /**
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  private static function getEmploymentSoftCreditTypes(): array {
    if (!\Civi::cache('metadata')->has('wmf_civicrm_employer_soft_credit_types')) {
      $types = \Civi\Api4\ContributionSoft::getFields(FALSE)
        ->setLoadOptions(['id', 'name'])
        ->addWhere('name', '=', 'soft_credit_type_id')
        ->execute()
        ->first()['options'];
      $employmentSoftCreditTypes = [];
      foreach ($types as $type) {
        if (in_array($type['name'], ['workplace', 'matched_gift'])) {
          $employmentSoftCreditTypes[$type['name']] = $type['id'];
        }
      }
      \Civi::cache('metadata')
        ->set('wmf_civicrm_employer_soft_credit_types', $employmentSoftCreditTypes);
      return $employmentSoftCreditTypes;
    }
    return \Civi::cache('metadata')->get('wmf_civicrm_employer_soft_credit_types');
  }

}

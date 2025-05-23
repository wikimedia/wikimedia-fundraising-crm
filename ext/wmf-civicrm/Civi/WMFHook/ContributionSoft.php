<?php

namespace Civi\WMFHook;

use Civi;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Api4\Contribution;
use Civi\Api4\RelationshipCache;

class ContributionSoft {

  /**
   * Create employer relationship for employer type soft credit.
   *
   * @see https://wikitech.wikimedia.org/w/index.php?title=Fundraising/Internal-facing/CiviCRM/Imports
   *
   * @param string $op
   * @param array $softCreditParams
   *
   * @throws \CRM_Core_Exception
   */
  public static function pre(string $op, array $softCreditParams): void {
    if ($op !== 'create') {
      return;
    }
    $softCreditTypeID = (int) ($softCreditParams['soft_credit_type_id'] ?? NULL);
    $isDonorAdvisedSoftCredit = in_array($softCreditTypeID, \Civi\WMFHelper\ContributionSoft::getDonorAdvisedFundSoftCreditTypes(), TRUE);
    $isEmployerSoftCredit = in_array($softCreditTypeID, \Civi\WMFHelper\ContributionSoft::getEmploymentSoftCreditTypes(), TRUE);
    if ($isEmployerSoftCredit || $isDonorAdvisedSoftCredit) {
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

      $anonymousContactID = \Civi\WMFHelper\Contact::getAnonymousContactID();

      $creditContact = Contact::get(FALSE)
        ->addWhere('id', '=', $softCreditParams['contact_id'])
        ->addSelect('contact_type', 'organization_name', 'employer_id', 'id')
        ->execute()
        ->first();
      if ($isEmployerSoftCredit) {
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
      elseif ($isDonorAdvisedSoftCredit
        && $creditContact['contact_type'] === 'Individual'
        && $creditContact['id'] !== $anonymousContactID
      ) {
        // Check for existing relationship, create new one if not exists.
        $existing = RelationshipCache::get(FALSE)
          ->addWhere('near_relation', '=', 'Holds a Donor Advised Fund of')
          ->addWhere('near_contact_id', '=', $contributionContact['contact_id'])
          ->addWhere('far_contact_id', '=', $creditContact['id'])
          ->execute()->first();
        if (!$existing) {
          Relationship::create(FALSE)
            ->setValues([
              'contact_id_a' => $contributionContact['contact_id'],
              'contact_id_b' => $creditContact['id'],
              'relationship_type_id:name' => 'Holds a Donor Advised Fund of',
              'start_date' => $contributionContact['receive_date'],
            ])
            ->execute();
        }
      }
    }
  }

}

<?php

namespace Civi\Api4\Action\WMFDonor;

use Civi\Api4\WMFDonor;
use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Update Annual Recurring donor segments and statuses.
 */
class UpdateAnnualDonors extends AbstractAction {

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $delinquentContacts = Contact::get($this->getCheckPermissions())
      ->addSelect('id')
      ->addJoin('ContributionRecur AS recur', 'EXCLUDE',
        ['id', '=', 'recur.contact_id'],
        ['recur.frequency_unit', '=', '"year"'],
        ['recur.contribution_status_id', 'NOT IN', [1, 3, 4]],
      )
      ->addWhere('wmf_donor.donor_status_id', '=', 12)
      ->addGroupBy('id')
      ->setLimit(10000)
      ->execute()->column('id');

    if ($delinquentContacts) {
      WMFDonor::update($this->getCheckPermissions())
        ->addValue('donor_segment_id', '')
        ->addValue('donor_status_id', '')
        ->addWhere('id', 'IN', $delinquentContacts)
        ->execute();
    }

    $threeMonthsAgo = '"' . date('Y-m-d', strtotime('-3 months')) . '"';
    $lapsedContacts = Contact::get($this->getCheckPermissions())
      ->addSelect('id')
      ->addJoin('ContributionRecur AS recur', 'EXCLUDE',
        ['id', '=', 'recur.contact_id'],
        ['recur.frequency_unit', '=', '"year"'],
        ['OR',
          [
            ['recur.cancel_date', '>=', $threeMonthsAgo],
            ['recur.end_date', '>=', $threeMonthsAgo],
          ],
        ],
      )
      ->addWhere('wmf_donor.donor_status_id', '=', 14)
      ->addGroupBy('id')
      ->setLimit(10000)
      ->execute()->column('id');

    if ($lapsedContacts) {
      WMFDonor::update($this->getCheckPermissions())
        ->addValue('donor_segment_id', '')
        ->addValue('donor_status_id', '')
        ->addWhere('id', 'IN', $lapsedContacts)
        ->execute();
    }

    $thirteenMonthsAgo = '"' . date('Y-m-d', strtotime('-13 months')) . '"';
    $oneTimeContacts = Contact::get($this->getCheckPermissions())
      ->addSelect('id')
      ->addJoin('ContributionRecur AS recur', 'EXCLUDE',
        ['id', '=', 'recur.contact_id'],
        ['recur.frequency_unit', '=', '"year"'],
        ['OR',
          [
            ['recur.cancel_date', '>=', $thirteenMonthsAgo],
            ['recur.end_date', '>=', $thirteenMonthsAgo],
          ],
        ],
      )
      ->addWhere('wmf_donor.donor_status_id', '=', 16)
      ->addGroupBy('id')
      ->setLimit(10000)
      ->execute()->column('id');

    if ($oneTimeContacts) {
      WMFDonor::update($this->getCheckPermissions())
        ->addValue('donor_segment_id', '')
        ->addValue('donor_status_id', '')
        ->addWhere('id', 'IN', $oneTimeContacts)
        ->execute();
    }

    $result[] = ['status' => 'success', 'count' => count($delinquentContacts) + count($lapsedContacts) + count($oneTimeContacts), 'message' => 'Annual recurring donor segments and statuses checked for updates.'];;
  }

}

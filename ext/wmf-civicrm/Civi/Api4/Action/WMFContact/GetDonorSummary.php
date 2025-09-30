<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class GetDonorSummary extends AbstractAction {

  /**
   * @var int
   * @required
   */
  protected $contact_id;

  /**
   * @var string
   * @required
   */
  protected $checksum;

  public function _run(Result $result) {
    if (!\CRM_Core_Permission::check('access CiviContribute') || !\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contact_id,  $this->checksum)) {
      \Civi::log('wmf')->warning('Donor portal access denied {contact_id} {checksum}', ['contact_id' => $this->contact_id, 'checksum' => $this->checksum]);
      throw new \CRM_Core_Exception('Authorization failed');
    }
    $mergedToId = Contact::getMergedTo()
      ->setContactId($this->contact_id)
      ->execute()
      ->first()['id'] ?? null;
    if ($mergedToId) {
      $this->contact_id = $mergedToId;
    }
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $this->contact_id)
      ->addSelect(
        'email_primary.email',
        'display_name',
        'first_name',
        'address_primary.street_address',
        'address_primary.city',
        'address_primary.state_province_id:abbr',
        'address_primary.postal_code',
        'address_primary.country_id:abbr',
      )
      ->execute()
      ->first();
    $email = $contact['email_primary.email'];

    // Since our database has a lot of duplicate contact records, we show donations for
    // all contacts with the same email address.
    $allContactIDsWithEmail = Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addSelect('contact_id')
      ->execute()->getArrayCopy();
    $contactIDList = array_column($allContactIDsWithEmail, 'contact_id');

    $allContributions = Contribution::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addOrderBy( 'receive_date', 'DESC' )
      ->addSelect(
        'id',
        'contribution_extra.original_amount',
        'contribution_extra.original_currency',
        'contribution_recur_id',
        'contribution_recur_id.frequency_unit',
        'financial_type_id:name',
        'payment_instrument_id:name',
        'receive_date'
      )->execute()->getArrayCopy();

    // The donor portal will show a list of all active recurring contributions with links to manage them.
    $recurringContributions = $this->getRecurringContributions($contactIDList, TRUE);
    // ... unless there are no active ones - then it will show the most recent inactive one with a link
    // to re-establish it.
    if (count($recurringContributions) === 0) {
      $recurringContributions = $this->getRecurringContributions($contactIDList, FALSE);
    }

    $result[] = [
      'id' => $this->contact_id,
      'name' => $contact['display_name'],
      'first_name' => $contact['first_name'],
      'email' => $email,
      'address' => [
        'street_address' => $contact['address_primary.street_address'],
        'city' => $contact['address_primary.city'],
        'state_province' => $contact['address_primary.state_province_id:abbr'],
        'postal_code' => $contact['address_primary.postal_code'],
        'country' => $contact['address_primary.country_id:abbr'],
      ],
      'hasMultipleContacts' => count($allContactIDsWithEmail) > 1,
      'contributions' => $this->mapContributions($allContributions),
      'recurringContributions' => $this->mapRecurringContributions($recurringContributions),
    ];
  }

  protected function getRecurringContributions(array $contactIDList, bool $active): array {
    $statusList = $active ?
      ['In Progress', 'Pending', 'Failing', 'Processing', 'Overdue'] :
      ['Completed', 'Failed', 'Cancelled'];
    $get = ContributionRecur::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addWhere('contribution_status_id:name', 'IN', $statusList)
      ->addOrderBy('modified_date', 'DESC')
      ->addSelect(
        'amount',
        'currency',
        'frequency_unit',
        'id',
        'next_sched_contribution_date',
        'payment_instrument_id:name',
        'contribution_status_id:name'
      );
    if (!$active) {
      $get->addJoin('Contribution AS contribution', 'LEFT')
        ->addSelect('MAX(contribution.receive_date) AS last_contribution_date')
        ->addGroupBy('id')
        ->setLimit(1);
    }
    // Under API4, a LEFT JOIN is a bit different from raw SQL. When no recurring contribution exists, it
    // returns a single record with all NULL values. Filter out these empty records that have no valid ID.
    return array_filter($get->execute()->getArrayCopy(), function ($row) {return !empty($row['id']); });
  }

  protected function mapContributions(array $allContributions): array {
    $mapped = [];
    foreach ($allContributions as $contribution) {
      $mapped[] = [
        'id' => $contribution['id'],
        'amount' => $contribution['contribution_extra.original_amount'],
        'currency' => $contribution['contribution_extra.original_currency'],
        'financial_type' => $contribution['financial_type_id:name'],
        'frequency_unit' => $contribution['contribution_recur_id.frequency_unit'],
        'is_recurring' => (bool) $contribution['contribution_recur_id'],
        'payment_method' => $contribution['payment_instrument_id:name'],
        'receive_date' => $contribution['receive_date'],
      ];
    }
    return $mapped;
  }

  protected function mapRecurringContributions(array $recurringContributions): array {
    $mapped = [];
    foreach ($recurringContributions as $recurringContribution) {
      $mapped[] = [
        'id' => $recurringContribution['id'],
        'amount' => $recurringContribution['amount'],
        'currency' => $recurringContribution['currency'],
        'frequency_unit' => $recurringContribution['frequency_unit'],
        'last_contribution_date' => $recurringContribution['last_contribution_date'] ?? null,
        'next_sched_contribution_date' => $recurringContribution['next_sched_contribution_date'],
        'payment_method' => $recurringContribution['payment_instrument_id:name'],
        'status' => $recurringContribution['contribution_status_id:name'],
      ];
    }
    return $mapped;
  }
}

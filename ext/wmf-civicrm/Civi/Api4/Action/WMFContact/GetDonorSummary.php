<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFHelper\ContributionRecur as ContributionRecurHelper;
use Civi\WMFHelper\ContributionRecur as RecurHelper;

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

  /**
   * If this were to be used for something that isn't the donor portal in the future,
   * we should make adjustments to no longer set last_donor_portal_login for that use.
   */
  public function _run(Result $result) {
    if (!\CRM_Core_Permission::check('access CiviContribute')) {
      \Civi::log('wmf')->warning('Donor portal access denied (Invalid permissions) {contact_id} {checksum}', ['contact_id' => $this->contact_id, 'checksum' => $this->checksum]);
      throw new \CRM_Core_Exception('Authorization failed');
    }
    if (!\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contact_id,  $this->checksum)) {
      \Civi::log('wmf')->warning('Donor portal access denied (Invalid credentials) {contact_id} {checksum}', ['contact_id' => $this->contact_id, 'checksum' => $this->checksum]);
      $result[] = [
        'error' => TRUE,
        'error_code' => 'InvalidCredentials',
        'message' => 'Invalid credentials'
      ];
      return;
    }

    $contact = $this->getContact();
    if (!$contact) {
      $mergedToId = Contact::getMergedTo()
        ->setContactId($this->contact_id)
        ->execute()
        ->first()['id'] ?? null;
      if ($mergedToId) {
        $this->contact_id = $mergedToId;
        $contact = $this->getContact();
      } else {
        throw new \CRM_Core_Exception("No contact found with id $this->contact_id");
      }
    }
    $email = $contact['email_primary.email'];

    // Since our database has a lot of duplicate contact records, we show donations for
    // all contacts with the same primary email address.
    $allContactIDsWithEmail = Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addWhere('is_primary', '=', TRUE)
      ->addSelect('contact_id')
      ->execute()->getArrayCopy();
    $contactIDList = array_column($allContactIDsWithEmail, 'contact_id');

    Contact::update(FALSE)
      ->addValue('Communication.last_donor_portal_login', 'now')
      ->addWhere('id', 'IN', $contactIDList)
      ->execute();

    $allContributions = Contribution::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addOrderBy( 'receive_date', 'DESC' )
      ->addSelect(
        'id',
        'contribution_extra.original_amount',
        'contribution_extra.original_currency',
        'contribution_recur_id',
        'contribution_recur_id.frequency_unit',
        'contribution_recur_id.contribution_status_id:name',
        'financial_type_id:name',
        'payment_instrument_id:name',
        'receive_date',
        'contribution_status_id:name'
      )->execute()->getArrayCopy();

    // The donor portal will show a list of all active recurring contributions with links to manage them.
    $recurringContributions = $this->getRecurringContributions($contactIDList, TRUE);
    $inactiveRecurringContributions = $this->getRecurringContributions($contactIDList, FALSE, count($recurringContributions) !== 0);
    $recurringContributions = array_merge($recurringContributions, $inactiveRecurringContributions);

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
      'recurringContributions' => $this->mapRecurringContributions(
        $recurringContributions, $contact['address_primary.country_id:abbr']
      )
    ];
  }

  /**
   * Gets the contacts recurring contributions
   *
   * @param array $contactIDList
   * @param bool $active
   * @param bool $getRecentInactiveRecurring flag to fetch recently (within 60 days) deactivated recurring
   * @return array
   */
  protected function getRecurringContributions(array $contactIDList, bool $active, bool $getRecentInactiveRecurring = FALSE): array {
    $statusList = $active ?
      ['In Progress', 'Pending', 'Failing', 'Processing', 'Overdue'] :
      ['Completed', 'Failed', 'Cancelled'];
    $get = ContributionRecur::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addWhere('contribution_status_id:name', 'IN', $statusList)
      ->addJoin('Contribution AS contribution', 'LEFT')
      ->addOrderBy('modified_date', 'DESC')
      ->addSelect(
        'amount',
        'cancel_reason',
        'currency',
        'frequency_unit',
        'id',
        'next_sched_contribution_date',
        'payment_instrument_id:name',
        'contribution_status_id:name',
        'payment_processor_id:name',
        'contribution_recur_smashpig.original_country:abbr',
        'MAX(contribution.receive_date) AS last_contribution_date'
      )->addGroupBy('id');
    if (!$active) {
      $get->setLimit(1);
      if ($getRecentInactiveRecurring) {
        $get->addWhere('cancel_date', '>=', 'ending_60.day');
      }
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
        'status' => $contribution['contribution_status_id:name'],
        'recurring_status' => $contribution['contribution_recur_id.contribution_status_id:name'],
      ];
    }
    return $mapped;
  }

  protected function mapRecurringContributions(array $recurringContributions, ?string $donorCountry): array {
    $mapped = [];
    foreach ($recurringContributions as $recurringContribution) {
      $mapped[] = [
        'id' => $recurringContribution['id'],
        'amount' => $recurringContribution['amount'],
        'country' => $recurringContribution['contribution_recur_smashpig.original_country:abbr'] ?? $donorCountry,
        'currency' => $recurringContribution['currency'],
        'frequency_unit' => $recurringContribution['frequency_unit'],
        'last_contribution_date' => $recurringContribution['last_contribution_date'] ?? null,
        'next_sched_contribution_date' => $recurringContribution['next_sched_contribution_date'],
        'payment_method' => $recurringContribution['payment_instrument_id:name'],
        'payment_processor' => $recurringContribution['payment_processor_id:name'],
        'status' => $recurringContribution['contribution_status_id:name'],
        'can_modify' => !RecurHelper::gatewayManagesOwnRecurringSchedule(
          $recurringContribution['payment_processor_id:name']
        ),
        'donor_cancelled' => in_array(
          $recurringContribution['cancel_reason'],
          ContributionRecurHelper::getDonorCancelReasons()
        ),
      ];
    }
    return $mapped;
  }

  protected function getContact() {
    return Contact::get(FALSE)
      ->addWhere('id', '=', $this->contact_id)
      ->addWhere('is_deleted', '=', 0)
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
  }
}

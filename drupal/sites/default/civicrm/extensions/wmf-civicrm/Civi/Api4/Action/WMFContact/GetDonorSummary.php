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
    if (!\CRM_Core_Permission::check('access CiviContribute') && !\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contact_id,  $this->checksum)) {
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
        'address_primary.street_address',
        'address_primary.city',
        'address_primary.state_province_id:abbr',
        'address_primary.postal_code',
        'address_primary.country_id:abbr',
      )
      ->execute()
      ->first();
    $email = $contact['email_primary.email'];
    $allContactIDsWithEmail = Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addSelect('contact_id')
      ->execute()->getArrayCopy();
    $contactIDList = array_column($allContactIDsWithEmail, 'contact_id');
    $allContributions = Contribution::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addSelect(
        'id',
        'contribution_recur_id',
        'contribution_extra.original_currency',
        'contribution_extra.original_amount',
        'payment_instrument_id:name'
      )->execute();
    $recurringContributions = ContributionRecur::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDList)
      ->addSelect(
        'amount',
        'currency',
        'frequency_unit',
        'id',
        'next_sched_contribution_date',
        'payment_instrument_id:name'
      )->execute();
    $result[] = [
      'id' => $this->contact_id,
      'name' => $contact['display_name'],
      'email' => $email,
      'address' => [
        'street_address' => $contact['address_primary.street_address'],
        'city' => $contact['address_primary.city'],
        'state_province' => $contact['address_primary.state_province_id:abbr'],
        'postal_code' => $contact['address_primary.postal_code'],
        'country' => $contact['address_primary.country_id:abbr'],
      ],
      'hasMultipleContacts' => count($allContactIDsWithEmail) > 1,
      'contributions' => $allContributions->getArrayCopy(),
      'recurringContributions' => $recurringContributions->getArrayCopy(),
    ];
  }
}

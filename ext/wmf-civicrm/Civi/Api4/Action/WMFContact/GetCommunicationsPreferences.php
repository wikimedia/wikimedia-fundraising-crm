<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * @method $this setContact_id(int $contactID)
 * @method $this setChecksum(string $checksum)
 */
class GetCommunicationsPreferences extends AbstractAction {

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

  public function _run(Result $result): void {
    if (!\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contact_id,  $this->checksum)) {
      \Civi::log('wmf')->warning('Email preferences access denied {contact_id} {checksum}', ['contact_id' => $this->contact_id, 'checksum' => $this->checksum]);
      throw new \CRM_Core_Exception('No result found');
    }
    $contact = $this->getContact();
    if (!$contact) {
      $mergedToId = Contact::getMergedTo(FALSE)
        ->setContactId( $this->contact_id )
        ->execute()
        ->first()['id'] ?? null;
      if ($mergedToId) {
        $this->contact_id = $mergedToId;
        $contact = $this->getContact();
      }
    }
    $hasActiveRecurringPaypalDonations = $this->getHasActiveRecurringPaypalDonations();

    if (!$contact) {
      throw new \CRM_Core_Exception('No result found');
    }
    $result[] = [
      'country' => $contact['address.country_id:name'] ?? NULL,
      'email' => $contact['email.email'] ?? NULL,
      'has_paypal' => $hasActiveRecurringPaypalDonations,
      'first_name' => $contact['first_name'] ?? NULL,
      'preferred_language' => $contact['preferred_language'] ?? NULL,
      'is_opt_in' => empty($contact['is_opt_out']) && ($contact['Communication.opt_in'] ?? NULL) !== FALSE,
      'snooze_date' => $contact['email_primary.email_settings.snooze_date'] ?? NULL
    ];
  }

  /**
   * Retrieve contact details for the given contact ID.
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  function getContact(): ?array {
    return Contact::get(FALSE)
      ->addWhere('id', '=', $this->contact_id)
      ->addWhere('is_deleted', '=', FALSE)
      ->setSelect([
        'preferred_language',
        'first_name',
        'address.country_id:name',
        'email.email',
        'is_opt_out',
        'Communication.opt_in',
        'email_primary.email_settings.snooze_date',
      ])
      ->addJoin('Address AS address', 'LEFT', ['address.is_primary', '=', 1])
      ->addJoin('Email AS email', 'LEFT', ['email.is_primary', '=', 1])
      ->execute()->first();
  }

  /**
   * Check if the contact has any active recurring PayPal donations.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  function getHasActiveRecurringPaypalDonations(): bool {
    return Contribution::get(FALSE)
      ->addWhere('contact_id', '=', $this->contact_id)
      ->addWhere('payment_instrument_id:name', '=', 'Paypal')
      ->addJoin('ContributionRecur AS recur', 'INNER',
        ['recur.id', '=', 'contribution_recur_id']
      )
      ->addWhere('recur.contribution_status_id:name', '=', 'In Progress')
      ->addSelect('id')->execute()->count() > 0;
  }
}

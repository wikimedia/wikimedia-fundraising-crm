<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Contact;
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

  public function _run(Result $result) {
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

    if (!$contact) {
      throw new \CRM_Core_Exception('No result found');
    }
    $result[] = [
      'country' => $contact['address.country_id:name'] ?? NULL,
      'email' => $contact['email.email'] ?? NULL,
      'first_name' => $contact['first_name'] ?? NULL,
      'preferred_language' => $contact['preferred_language'] ?? NULL,
      'is_opt_in' => empty($contact['is_opt_out']) && ($contact['Communication.opt_in'] ?? NULL) !== FALSE,
      'snooze_date' => $contact['email_primary.email_settings.snooze_date'] ?? NULL
    ];
  }

  function getContact() {
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

}

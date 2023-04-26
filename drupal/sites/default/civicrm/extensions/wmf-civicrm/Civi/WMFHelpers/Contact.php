<?php

namespace Civi\WMFHelpers;

use Civi;
use Civi\Api4\ContributionSoft;

class Contact {

  /**
   * Get the id of the organization whose nick name (preferably) or name matches.
   *
   * This function is used during imports where the organization may go by more
   * than one name.
   *
   * If there are no possible matches this will fail, unless isCreateIfNotExists
   * is passed in.
   *
   * It will also fail if there
   * are multiple possible matches of the same priority (ie. multiple nick names
   * or multiple organization names.)
   *
   * @param string $organizationName
   * @param bool $isCreateIfNotExists
   * @param array $createParameters
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  public static function getOrganizationID(string $organizationName, bool $isCreateIfNotExists = FALSE, $createParameters = []): int {
    // Using the Civi Statics pattern for php caching makes it easier to reset in unit tests.
    self::resolveOrganizationName($organizationName);
    $contactID = Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] ?? NULL;
    if (!$contactID) {
      $contacts = \Civi\Api4\Contact::get(FALSE)
        ->addWhere('contact_type', '=', 'Organization')
        ->addWhere('organization_name', '=', $organizationName)
        ->addSelect('id', 'organization_name')->execute();
      if (count($contacts) === 1) {
        Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = $contacts->first()['id'];
      }
      elseif ($isCreateIfNotExists && count($contacts) === 0) {
        Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = \Civi\Api4\Contact::create(FALSE)->setValues(array_merge([
            'organization_name' => $organizationName,
          ], $createParameters)
        )->execute()->first()['id'];
      }
      else {
        \Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = FALSE;
      }
    }
    if (\Civi::$statics['wmf_contact']['organization'][$organizationName]['id']) {
      return \Civi::$statics['wmf_contact']['organization'][$organizationName]['id'];
    }
    throw new \CRM_Core_Exception(
      ts("Did not find exactly one Organization with the details: %1 You will need to ensure a single Organization record exists for the contact first",
        [
          1 => $organizationName,
        ]
      )
    );
  }

  /**
   * Get the resolved name of an organization.
   *
   * This function is used during imports where the organization may go by more
   * than one name.
   *
   * @param string $organizationName
   *
   * @return string
   *   The name of an organization that matches the nick_name if one exists,
   *   otherwise the passed in name.
   * @throws \CRM_Core_Exception
   */
  public static function resolveOrganizationName(string $organizationName): string {
    if (!isset(Civi::$statics['wmf_contact']['organization_resolved_name'][$organizationName])) {
      self::loadContactByNickName($organizationName);
      if (empty(Civi::$statics['wmf_contact']['organization'][$organizationName]['organization_name'])) {
        Civi::$statics['wmf_contact']['organization'][$organizationName] = ['organization_name' => $organizationName, 'id' => NULL];
      }
    }
    return Civi::$statics['wmf_contact']['organization'][$organizationName]['organization_name'];
  }

  /**
   * Get the organization name, using caching.
   *
   * @param int $id
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public static function getOrganizationName(int $id): ?string {
    if (empty(Civi::$statics['wmf_contact']['organization_ids'][$id])) {
      $organizationName = Civi::$statics['wmf_contact']['organization_ids'][$id] = Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $id)
        ->addWhere('contact_type', '=', 'Organization')
        ->addSelect('organization_name')->execute()->first()['organization_name'] ?? NULL;
      if ($organizationName) {
        Civi::$statics['wmf_contact']['organization'][$organizationName] = [
          'organization_name' => $organizationName,
          'id' => $id
        ];
      }
    }
    return Civi::$statics['wmf_contact']['organization_ids'][$id];
  }

  /**
   * Is the individual employed by the given organization, taking soft credits into account.
   *
   * @param int $organizationID
   * @param int $contactID
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public static function isContactSoftCreditorOf(int $organizationID, int $contactID): bool {
    $softCredits = ContributionSoft::get(FALSE)->addWhere('contact_id', '=', $contactID)
      ->addSelect('contribution_id.contact_id')->execute();
    if (count($softCredits) === 0) {
      return FALSE;
    }
    foreach ($softCredits as $softCredit) {
      if ($softCredit['contribution_id.contact_id'] === $organizationID) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Load contact by nick name and store in statics if found.
   *
   * @param string $organizationName
   *
   * @throws \CRM_Core_Exception
   */
  private static function loadContactByNickName(string $organizationName): void {
    $contacts = \Civi\Api4\Contact::get(FALSE)
      ->addWhere('contact_type', '=', 'Organization')
      ->addWhere('nick_name', '=', $organizationName)
      ->addSelect('id', 'organization_name')->execute();
    if (count($contacts) === 1) {
      Civi::$statics['wmf_contact']['organization'][$organizationName] = $contacts->first();
    }
  }


  /**
   * @param string|null $email
   * @param string|null $firstName
   * @param string|null $lastName
   * @param string|null $organizationName
   * @param int|null $organizationID Organization ID if known (organizationName not used if so)
   *
   * @return false|int
   * @throws \CRM_Core_Exception
   */
  public static function getIndividualID(?string $email, ?string $firstName, ?string $lastName, ?string $organizationName, ?int $organizationID = NULL) {
    if (!$email && !$firstName && !$lastName) {
      // We do not have an email or a name, match to our anonymous contact (
      // note address details are discarded in this case).
      return self::getAnonymousContactID();
    }
    if (!$organizationID) {
      $organizationID = $organizationName ? self::getOrganizationID($organizationName) : NULL;
    }

    $contactGet = \Civi\Api4\Contact::get(FALSE)
      ->addWhere('is_deleted', '=', 0)
      ->addWhere('contact_type', '=', 'Individual')
      ->addOrderBy('organization_name', 'DESC')
      ->addSelect('employer_id', 'organization_name', 'email_primary.email');

    foreach (['last_name' => $lastName, 'first_name' => $firstName, 'email_primary.email' => $email] as $fieldName => $fieldValue) {
      if ($fieldValue) {
        $contactGet->addWhere($fieldName, '=', $fieldValue);
      }
    }
    $contacts = $contactGet->execute();

    if (count($contacts) === 1) {
      $contact = $contacts->first();
      if ($email
        || ($organizationID && $contact['employer_id'] === $organizationID)
        || self::isContactSoftCreditorOf($organizationID, $contact['id'])) {
        return $contact['id'];
      }
      return FALSE;
    }
    if (count($contacts) > 1) {
      $possibleContacts = [];
      foreach ($contacts as $contact) {
        if (($organizationID && $contact['employer_id'] === $organizationID) || self::isContactSoftCreditorOf($organizationID, $contact['id'])) {
          $possibleContacts[] = $contact['id'];
        }
        if (count($possibleContacts) > 1) {
          foreach ($possibleContacts as $index => $possibleContactID) {
            if (
              $contacts->indexBy('id')[$possibleContactID]['employer_id']
              !== self::getOrganizationID($organizationName)
            ) {
              unset($possibleContacts[$index]);
            }
          }
        }
      }
      return (count($possibleContacts) === 1) ? reset($possibleContacts) : FALSE;
    }
    return FALSE;
  }

  /**
   * Get the ID of our anonymous contact.
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public static function getAnonymousContactID(): int {
    static $contactID = NULL;
    if (!$contactID) {
      $contactID = (int) \Civi\Api4\Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_type', '=', 'Individual')
        ->addWhere('first_name', '=', 'Anonymous')
        ->addWhere('last_name', '=', 'Anonymous')
        ->addWhere('email_primary.email', '=', 'fakeemail@wikimedia.org')
        ->execute()->first()['id'];
    }
    if (!$contactID) {
      // It always exists on production....
      throw new \CRM_Core_Exception('The anonymous contact does not exist in your dev environment. Ensure exactly one contact is in CiviCRM with the email fakeemail@wikimedia.org and first name and last name being Anonymous');
    }
    return $contactID;
  }

}

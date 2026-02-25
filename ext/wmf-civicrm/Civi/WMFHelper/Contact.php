<?php

namespace Civi\WMFHelper;

use Civi;
use Civi\Api4\ContributionSoft;
use Civi\Api4\RelationshipCache;

class Contact {

  /**
   * Get the id of the organization whose nick name (preferably) or name matches.
   *
   * This function is used during imports where the organization may go by more
   * than one name.
   *
   * If there are no possible matches, it will return null and we'll let the org be created later.
   * If there are multiple matches, it will throw an exception, forcing the use to resolve before importing.
   *
   * Note this ignores the UI selected dedupe rule and first/unique match selection,
   * potentially confusing the user who won't understand why the behavior doesn't match their selections.
   * Ideally, we would instead fetch and use the selected dedupe rule and first/unique match selection
   * and then add our org name = nickname match on top of that.
   *
   *
   * @param string $organizationName
   *
   * @return int|null
   *
   * @throws \CRM_Core_Exception
   */
  public static function getOrganizationID(string $organizationName): int|null {
    // Using the Civi Statics pattern for php caching makes it easier to reset in unit tests.
    self::resolveOrganizationName($organizationName);
    $contactID = Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] ?? NULL;
    if (!isset($contactID)) {
      $contacts = \Civi\Api4\Contact::get(FALSE)
        ->addWhere('contact_type', '=', 'Organization')
        ->addWhere('organization_name', '=', $organizationName)
        ->addSelect('id', 'organization_name')->execute();
      if (count($contacts) === 1) {
        Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = $contacts->first()['id'];
      }
      elseif (count($contacts) > 1) {
        throw new \CRM_Core_Exception(
          ts("Found more than one organization named: %1. Please merge them or rename one.",
            [1 => $organizationName]));
      }
      else {
        \Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = FALSE;
      }
    }
    return \Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] ?: NULL;
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
          'id' => $id,
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
   * @param string|null $postalCode
   * @param string|null $organizationName
   * @param int|null $organizationID Organization ID if known (organizationName not used if so)
   *
   * @return false|int
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function getIndividualID(?string $email, ?string $firstName, ?string $lastName, ?string $postalCode, ?string $organizationName, ?int $organizationID = NULL) {
    if (!$email && (!$firstName || $firstName === 'Anonymous') && (!$lastName|| $lastName === 'Anonymous')) {
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
      ->addSelect('employer_id', 'organization_name', 'email_primary.email', 'address_primary.postal_code');

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
        || ($organizationID && self::isContactSoftCreditorOf($organizationID, $contact['id']))) {
        return $contact['id'];
      }
      return FALSE;
    }

    if (count($contacts) > 1) {
      $possibleContacts = [];
      foreach ($contacts as $contact) {
        if ($organizationID && ($contact['employer_id'] === $organizationID || self::isContactSoftCreditorOf($organizationID, $contact['id']))) {
          $possibleContacts[] = $contact['id'];
        }
      }
      if (count($possibleContacts) > 1) {
        foreach ($possibleContacts as $index => $possibleContactID) {
          $employerID = $contacts->indexBy('id')[$possibleContactID]['employer_id'];
          // If they are employed by someone else then they have possibly moved on.
          if (($employerID && $employerID !== $organizationID) || !$employerID) {
            unset($possibleContacts[$index]);
          }
        }
      }
      // If we still have 2+ matches, try zip code and return the first match if we find one.
      if ((count($possibleContacts) > 1) && $postalCode) {
        foreach ($possibleContacts as $possibleContactID) {
          $zipCode = $contacts->indexBy('id')[$possibleContactID]['address_primary.postal_code'];
          if (mb_substr($zipCode, 0, 5) === mb_substr($postalCode, 0, 5)) {
            return $possibleContactID;
          }
        }
      }
      if (count($possibleContacts) >= 1) {
        return reset($possibleContacts);
      }
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
    if (!isset(\Civi::$statics[__CLASS__]['anonymous'])) {
      \Civi::$statics[__CLASS__]['anonymous'] = (int) \Civi\Api4\Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_type', '=', 'Individual')
        ->addWhere('first_name', '=', 'Anonymous')
        ->addWhere('last_name', '=', 'Anonymous')
        ->addWhere('email_primary.email', '=', 'fakeemail@wikimedia.org')
        ->execute()->first()['id'];
    }
    if (!\Civi::$statics[__CLASS__]['anonymous']) {
      // It always exists on production....
      throw new \CRM_Core_Exception('The anonymous contact does not exist in your dev environment. Ensure exactly one contact is in CiviCRM with the email fakeemail@wikimedia.org and first name and last name being Anonymous');
    }
    return \Civi::$statics[__CLASS__]['anonymous'];
  }

  /**
   * Check if there is any other contacts share same primary email
   * with the given contact id, return all ids, need to merge
   * @param int $contact_id
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function duplicateContactIds(int $contact_id): array {
    $contactIds = [];
    // check multi contact share same email address (not merged yet)
    $contacts = \Civi\Api4\Email::get(FALSE)
      ->addSelect('duplicateemail.contact_id')
      ->addJoin('Email AS duplicateemail', 'INNER', ['email', '=', 'duplicateemail.email'], ['id', '<>', 'duplicateemail.id'])
      ->addWhere('contact_id', '=', $contact_id)
      ->execute();
    foreach ($contacts as $contact) {
      $contactIds[] = $contact['duplicateemail.contact_id'];
    }
    array_push($contactIds, $contact_id);
    return $contactIds;
  }

}

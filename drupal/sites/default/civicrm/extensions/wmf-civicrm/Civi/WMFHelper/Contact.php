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
   * @param string|null $organizationName
   * @param int|null $organizationID Organization ID if known (organizationName not used if so)
   * @param bool $strictGiftMode Require the user to resolve gift duplicates
   *   A gift duplicate is where more than one person with the same name details has either
   *   an employer relationship with the organization or prior matched gifts. In strict mode
   *   an exception will be thrown in this case, requiring the user to merge. Otherwise
   *   one is chosen. For Benevity imports we do not use strict mode but for the new imports
   *   the volume is such that users can reasonably clean this up at this stage.
   *
   * @return false|int
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function getIndividualID(?string $email, ?string $firstName, ?string $lastName, ?string $organizationName, ?int $organizationID = NULL, $strictGiftMode = TRUE) {
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
        || ($organizationID && self::isContactSoftCreditorOf($organizationID, $contact['id']))) {
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
      }
      if (count($possibleContacts) > 1) {
        foreach ($possibleContacts as $index => $possibleContactID) {
          $employerID = $contacts->indexBy('id')[$possibleContactID]['employer_id'];
          // If they are employed by someone else then they have possibly moved on.
          if (
            ($employerID && $employerID !== $organizationID)
            // If strict gift mode is FALSE then we de-prioritise those without relationships.
            || (!$strictGiftMode && !$employerID)
          ) {
            unset($possibleContacts[$index]);
          }
          if (!$employerID && $strictGiftMode) {
            // In strict mode we decide that a duplicate involving a contact with no
            // employer, linked by prior soft credits, and a contact with a relationship
            // still needs the user to resolve (as the obvious solution is to merge them).
            // However, if the user really thinks they should not be merged
            // then having a disabled or ended relationship will denote their connection is over.
            // I can't see this arising but without this there would be no way to 'force'
            // the import to ignore the no-longer-employed-duplicate-name donor.
            $priorRelationships = RelationshipCache::get(FALSE)
              ->addWhere('near_relation', '=', 'Employee of')
              ->addWhere('is_current', '=', FALSE)
              ->addWhere('near_contact_id', '=', $possibleContactID)
              ->addWhere('far_contact_id', '=', $organizationID)
              ->selectRowCount()
              ->execute()->rowCount;
            if ($priorRelationships) {
              unset($possibleContacts[$index]);
            }

          }
        }
      }
      if (!$strictGiftMode || count($possibleContacts) === 1) {
        return reset($possibleContacts);
      }
      if (count($possibleContacts) > 1) {
        throw new \CRM_Core_Exception('Multiple contact matches with employer connection: ' . implode(',' , $possibleContacts));
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

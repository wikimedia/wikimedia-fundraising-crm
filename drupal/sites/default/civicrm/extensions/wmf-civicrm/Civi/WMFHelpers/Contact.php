<?php

namespace Civi\WMFHelpers;

use Civi;
use Civi\WMFException\WMFException;

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
   * @throws \Civi\WMFException\WMFException
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
    throw new WMFException(
      WMFException::IMPORT_CONTRIB,
      t("Did not find exactly one Organization with the details: @organizationName. You will need to ensure a single Organization record exists for the contact first",
        [
          '@organizationName' => $organizationName,
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

}

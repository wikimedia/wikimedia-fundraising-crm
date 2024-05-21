<?php

namespace Civi\WMFHook;

use Civi\Api4\LocationType;

class Address {

  /**
   * Process old addresses when a Change Of Address comes in.
   *
   * The address source 'ncoa' indicates the address has come in
   * from a National Change of Address. These generally come from
   * a third party provider like DataAxle. The agreed handling for these is
   * to move the existing address, if different, to a new address type
   * of 'old 2024' (for example).
   *
   * @return void
   */
  public static function pre($op, &$entity): void {
    if ($op === 'delete' || empty($entity['custom'])
      || empty($entity['is_primary'])
      // The id would be loaded here because it will have decided to update the
      // existing primary.
      || empty($entity['id'])
      // Contact ID can be empty for (e.g.) event location addresses.
      // It is also possible for it to be empty because of a very
      // limited update (e.g. via the api) - but we know that in
      // the scenario we are really focussed on, imports, it won't be.
      || empty($entity['contact_id'])
    ) {
      return;
    }

    $updateDate = $existingAddress = NULL;
    foreach ($entity['custom'] as $customValues) {
      $customValue = reset($customValues);
      if ($customValue['column_name'] === 'source' && $customValue['value'] === 'ncoa') {
        // This is a National Change of Address update.
        $existingAddress = \Civi\Api4\Address::get(FALSE)
          ->addWhere('contact_id', '=', $entity['contact_id'])
          ->addWhere('is_primary', '=', TRUE)
          ->addSelect('address_data.*', '*')
          ->addOrderBy('is_primary', 'DESC')
          ->execute()->first();
      }
      if ($customValue['column_name'] === 'update_date') {
        // Not using this at the moment, but we could do some comparison.
        $updateDate = $customValue['value'];
      }
    }
    if ($existingAddress) {
      if (strtolower($existingAddress['street_address']) === strtolower($entity['street_address'])) {
        // Probably the same address, do nothing. We can get away with no checking
        // all the fields as the NCOA should be authoritative, keeping the
        // old address is mostly precautionary.
        return;
      }
      if (empty($existingAddress['street_address'])
        && empty($existingAddress['city'])
        && empty($existingAddress['state_province_id'])
        && (
          empty($existingAddress['country_id']) ||  $existingAddress['country_id'] === $entity['country_id']
        )
      ) {
        // Looks like the existing address is just a country, ignore.
        return;
      }
      // OK so we have an old address to preserve, erm, without causing a loop preferably.
      unset($entity['id']);
      \CRM_Core_DAO::executeQuery('
        UPDATE civicrm_address
        SET is_primary = 0,
        location_type_id = %2
        WHERE id = %1', [
          1 => [$existingAddress['id'], 'Integer'],
          2 => [self::getOldLocationTypeID(), 'Integer'],
        ]
      );
    }
  }

  private static function getOldLocationTypeID(): int {
    $locationName = 'Old_' . date('Y');
    $locationTypeID = \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', $locationName);
    if ($locationTypeID) {
      return $locationTypeID;
    }
    return LocationType::create(FALSE)
      ->setValues([
        'name' => $locationName,
        'description' => 'Old address in ' . date('Y') . ' if new one was added/imported',
        'display_name' => 'Old' . date('Y'),
      ])
      ->execute()->first()['id'];
  }

}

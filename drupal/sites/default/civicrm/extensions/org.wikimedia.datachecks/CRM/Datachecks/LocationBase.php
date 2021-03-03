<?php

/**
 * Class CRM_Datachecks_DuplicateLocation
 *
 * Class to do checks to ensure people do not have duplicates of a particular location type.
 */
class CRM_Datachecks_LocationBase {

  /**
   * List of entities to check.
   *
   * @var array
   */
  protected $entities = ['email', 'phone', 'address', 'im'];

  /**
   * Get the available location types with a light re-order to make our preferences for reassignment (in order)
   *  - Main
   *  - Other
   *  - Home
   *  - Mailing
   *  - Billing
   */
  protected function getLocationTypes() {
    $locationTypes = civicrm_api3('Address', 'getoptions', ['field' => 'location_type_id'])['values'];
    $preferredOrder = array_intersect_key(['Main' => 0, 'Other' => 1, 'Home' => 2, 'Mailing' => 3, 'Billing' => 4], array_flip($locationTypes));
    return array_merge($preferredOrder, array_flip($locationTypes));
  }

}

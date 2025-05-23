<?php
// Class to hold wmf helper functions.

namespace Civi\WMFHelper;

use Civi\Api4\CustomField;

class CustomData {

  /**
   * Get the custom field name for a given id.
   *
   * @param int $id
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function getCustomFieldNameFromID(int $id): string {
    if (!\Civi::cache('metadata')->has('wmf_custom_field_name_mapping')) {
      \Civi::cache('metadata')->set('wmf_custom_field_name_mapping', (array) CustomField::get(FALSE)
        ->setSelect(['id', 'name'])
        ->execute()->indexBy('id')
      );
    }
    return \Civi::cache('metadata')->get('wmf_custom_field_name_mapping')[$id]['name'];
  }
}

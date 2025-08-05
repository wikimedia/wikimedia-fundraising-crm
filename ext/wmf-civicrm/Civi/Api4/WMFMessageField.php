<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFMessageField\Get;
use Civi\Api4\Generic\BasicGetFieldsAction;

class WMFMessageField extends Generic\AbstractEntity {

  /**
   * Get the values that would be calculated for a WMF Donor.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFMessageField\Get
   */
  public static function get(bool $checkPermissions = TRUE): Get {
    return (new Get(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [
        [
          'name' => 'name',
          'description' => 'Field name',
          'input_type' => 'Text',
        ],
        [
          'name' => 'title',
          'description' => 'Title',
          'input_type' => 'Text',
        ],
        [
          'name' => 'data_type',
          'description' => 'Data Type',
          'input_type' => 'Text',
        ],
        [
          'name' => 'mapped_to',
          'description' => 'Mapped to',
          'input_type' => 'Text',
        ],
        [
          'name' => 'description',
          'description' => 'Description',
          'input_type' => 'Text',
        ],
        [
          'name' => 'api_entity',
          'description' => 'Api entity',
          'input_type' => 'Text',
        ],
        [
          'name' => 'api_field',
          'description' => 'Api field',
          'input_type' => 'Text',
        ],
        [
          'name' => 'comment',
          'description' => 'Comment',
          'input_type' => 'Text',
        ],
        [
          'name' => 'used_for',
          'description' => 'Used for',
          'input_type' => 'Text',
        ],
        [
          'name' => 'notes',
          'description' => 'Notes',
          'input_type' => 'Text',
        ],
      ];

    });
  }

}

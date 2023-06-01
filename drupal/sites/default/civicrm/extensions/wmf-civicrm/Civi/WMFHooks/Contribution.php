<?php

namespace Civi\WMFHooks;

use Civi;
use Civi\WMFException\WMFException;
use Civi\WMFHelpers\Database;

class Contribution {

  public static function pre($op, &$contribution): void {
    switch ($op) {
      case 'create':
      case 'edit':
        // Add derived wmf_contribution_extra fields to contribution parameters
        if (Database::isNativeTxnRolledBack()) {
          throw new WMFException(
            WMFException::IMPORT_CONTRIB,
            'Native txn rolled back before running pre contribution hook'
          );
        }
        $extra = wmf_civicrm_get_wmf_contribution_extra($contribution);

        if ($extra) {
          $map = wmf_civicrm_get_custom_field_map(
            array_keys($extra), 'contribution_extra'
          );
          $mapped = [];
          foreach ($extra as $key => $value) {
            $mapped[$map[$key]] = $value;
          }
          $contribution += $mapped;
          // FIXME: Seems really ugly that we have to do this, but when
          // a contribution is created via api3, the _pre hook fires
          // after the custom field have been transformed and copied
          // into the 'custom' key
          $formatted = [];
          _civicrm_api3_custom_format_params($mapped, $formatted, 'Contribution');
          if (isset($contribution['custom'])) {
            $contribution['custom'] += $formatted['custom'];
          }
          else {
            $contribution['custom'] = $formatted['custom'];
          }
        }

        break;
    }
  }

}

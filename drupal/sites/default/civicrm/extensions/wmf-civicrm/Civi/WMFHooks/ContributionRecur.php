<?php

namespace Civi\WMFHooks;

use Civi;

class ContributionRecur {
  public static function pre($op, &$entity) {
    // We explicitly want to allow the contribution_recur row to have a different
    // amount and currency from the contribution row for non-USD transactions.
    // Civi wants them to match, and calls
    // CRM_Contribute_BAO_ContributionRecur::updateOnTemplateUpdated when we edit
    // a contribution (e.g. to mark it refunded). This is our only access point to
    // undo that, but we want to be very targeted so as not to mess with any other
    // recurring contribution edits. The edit on updateOnTemplateUpdated will always
    // strictly change the amount and currency (and will set currency to USD), as
    // well as updating the modified_date. Besides those, it seems to always come in
    // with ['custom' => null, 'id' => <int>, 'check_permissions' => false ].
    // If necessary, we could add another check using debug_backtrace to see if
    // the updateOnTemplateUpdated function is hit.
    if ($op === 'edit') {
      $keys = array_keys($entity);
      if (
        count($keys) === 6 &&
        isset($entity['amount']) &&
        isset($entity['modified_date']) &&
        isset($entity['currency']) &&
        $entity['currency'] === 'USD' &&
        in_array('custom', $keys) &&
        $entity['custom'] === null
      ) {
        Civi::log('wmf')->info('Thwarting contribution_recur mutation from updateOnTemplateUpdated');
        unset($entity['amount']);
        unset($entity['currency']);
        unset($entity['custom']);
        unset($entity['modified_date']);
      }
    }
  }
}

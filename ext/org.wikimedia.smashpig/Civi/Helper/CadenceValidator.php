<?php

namespace Civi\Helper;

class CadenceValidator {
  public static function hasErrors(?string $cadence): ?string {
    if (!preg_match('/^([0-9]+)(,[0-9]+)*$/', $cadence)) {
      return 'Retry cadence should be a comma separated list of integers.';
    }
    $days = explode(',', $cadence);
    $prevDay = 0;
    foreach ($days as $day) {
      if ($day <= $prevDay) {
        return 'Each number in retry cadence should be larger than the one before it.';
      }
      if ($day > 28) {
        return 'Retrying past 28 days will lead to overlapping charges';
      }
      $prevDay = $day;
    }
    return null;
  }
}

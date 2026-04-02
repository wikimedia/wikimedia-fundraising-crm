<?php

namespace Civi\SmashPig;

use Civi\Core\Event\GenericHookEvent;
use Civi\Helper\CadenceValidator;

class Hooks {
  public static function validateSettings(GenericHookEvent $event): void {
    if ($event->formName === "CRM_Admin_Form_Generic") {
      if (isset($event->fields['smashpig_recurring_retry_cadence'])) {
        $val = $event->fields['smashpig_recurring_retry_cadence'];
        $cadenceError = CadenceValidator::hasErrors($val);
        if ($cadenceError) {
          $event->errors['smashpig_recurring_retry_cadence'] = $cadenceError;
        }
      }
    }
  }
}

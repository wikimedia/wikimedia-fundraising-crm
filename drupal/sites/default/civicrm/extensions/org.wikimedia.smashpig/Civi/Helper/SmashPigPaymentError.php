<?php

namespace Civi\Helper;

use SmashPig\PaymentProviders\Responses\PaymentDetailResponse;

class SmashPigPaymentError {

  /**
   * Handle different error formats to return the actual
   * text string of the error
   */
  public static function getErrorText($errorResponse) {
    if ($errorResponse instanceof PaymentDetailResponse) {
      if (
        count($errorResponse->getErrors()) &&
        method_exists($errorResponse->getErrors()[0], 'getDebugMessage')
      ) {
        $errorMessage = $errorResponse->getErrors()[0]->getDebugMessage();
      }
      else {
        $errorMessage = 'Unknown problem charging payment';
      }
    }
    else {
      $errorMessage = $errorResponse;
    }
    return $errorMessage;
  }

}

<?php

namespace Civi\Helper;

use SmashPig\PaymentProviders\Responses\PaymentProviderExtendedResponse;

class SmashPigPaymentError {

  /**
   * Handle different error formats to return the actual
   * text string of the error
   *
   * @param mixed $errorResponse The error response object or string.
   * @return string The error message text.
   */
  public static function getErrorText($errorResponse): string {
    if ($errorResponse instanceof PaymentProviderExtendedResponse) {
      if (
        count($errorResponse->getErrors()) &&
        method_exists($errorResponse->getErrors()[0], 'getDebugMessage')
      ) {
        $errorMessage = $errorResponse->getErrors()[0]->getDebugMessage();
      }
      else {
        $errorMessage = 'Unknown problem charging payment';
      }
      return $errorMessage;
    }

    // safety net
    return is_string($errorResponse) ? $errorResponse : 'An unknown payment error occurred';
  }

}

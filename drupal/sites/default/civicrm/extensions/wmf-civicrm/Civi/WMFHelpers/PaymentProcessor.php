<?php

namespace Civi\WMFHelpers;

class PaymentProcessor {

  /**
   * Get the payment processor id for our gateway.
   *
   * @param string $gateway
   *
   * @return int[]|false
   *
   * @throws \API_Exception
   */
  public static function getPaymentProcessorID(string $gateway) {
    $processors = self::getPaymentProcessors();
    return $processors[$gateway] ?? FALSE;
  }

  /**
   * Get the available payment processors.
   *
   * @return int[]
   *   e.g ['adyen' => 1, 'paypal_ec' => 1]
   *
   * @throws \API_Exception
   */
  public static function getPaymentProcessors(): array {
    // Note that the options themselves are cached already in core.
    // This caching doesn't add much more.
    $processors = \Civi::cache('metadata')->get('civicrm_payment_processors');
    if ( !$processors ) {
      $processors = [];
      $options = \Civi\Api4\ContributionRecur::getFields( FALSE )
        ->setLoadOptions(['id', 'name'])
        ->addWhere('name', '=', 'payment_processor_id')
        ->execute()->first()['options'];
      foreach ($options as $option) {
        $processors[$option['name']] = $option['id'];
      }
      \Civi::cache('metadata')->set('civicrm_payment_processors', $processors);
    }
    return $processors;
  }
}
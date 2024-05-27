<?php

namespace Civi\WMFHelper;

class PaymentProcessor {

  /**
   * Get the payment processor id for our gateway.
   *
   * @param string $gateway
   *
   * @return int|false
   *
   * @throws \CRM_Core_Exception
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
   * @throws \CRM_Core_Exception
   */
  public static function getPaymentProcessors(): array {
    $processors = [];
    $options = \Civi\Api4\ContributionRecur::getFields(FALSE)
      ->setLoadOptions(['id', 'name'])
      ->addWhere('name', '=', 'payment_processor_id')
      ->execute()->first()['options'];
    foreach ($options as $option) {
      $processors[$option['name']] = (int) $option['id'];
    }
    return $processors;
  }

}

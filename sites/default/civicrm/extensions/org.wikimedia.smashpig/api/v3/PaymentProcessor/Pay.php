<?php

use Civi\Payment\Exception\PaymentProcessorException;

/**
 * Action payment.
 *
 * @param array $params
 *
 * @return array
 *   API result array.
 * @throws CiviCRM_API3_Exception
 */
function civicrm_api3_payment_processor_pay($params) {
  $processor = Civi\Payment\System::singleton()
    ->getById($params['payment_processor_id']);
  $processor->setPaymentProcessor(
    civicrm_api3('PaymentProcessor', 'getsingle', [
      'id' => $params['payment_processor_id']
    ])
  );
  try {
    $result = $processor->doPayment($params);
  } catch (PaymentProcessorException $e) {
    throw new CiviCRM_API3_Exception('Payment failed', 'EXTERNAL_FAILURE', [], $e);
  }
  return civicrm_api3_create_success([$result], $params);
}

/**
 * Action payment.
 *
 * @param array $params
 */
function _civicrm_api3_payment_processor_pay_spec(&$params) {
  $params['payment_processor_id']['api.required'] = 1;
  $params['amount']['api.required'] = 1;
  $params['payment_action'] = [
    'api.default' => 'purchase',
  ];
}

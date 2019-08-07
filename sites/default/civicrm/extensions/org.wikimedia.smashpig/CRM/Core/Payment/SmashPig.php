<?php

use Civi\API\Exception\NotImplementedException;
use Civi\Payment\Exception\PaymentProcessorException;
use SmashPig\PaymentProviders\PaymentProviderFactory;

require_once "CRM/SmashPig/ContextWrapper.php";

class CRM_Core_Payment_SmashPig extends CRM_Core_Payment {

  /**
   * Constructor.
   *
   * @param string $mode
   *   the mode of operation: live or test.
   *
   * @param array $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $this->_paymentProcessor = $paymentProcessor;

    // Live or test.
    $this->_mode = $mode;
  }

  /**
   * Are back office payments supported.
   *
   * e.g paypal standard won't permit you to enter a credit card associated
   * with someone else's login.
   * The intention is to support off-site (other than paypal) & direct debit but that is not all working yet so to
   * reach a 'stable' point we disable.
   *
   * @return bool
   */
  protected function supportsBackOffice() {
     return FALSE;
  }

  /**
   * Just does a tokenized credit card payment for now.
   *
   * @param array $params requires at least token, amount, currency, invoice_id,
   *  is_recur, and description
   *
   * @return array with processor_id set to the processor's payment ID
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doDirectPayment(&$params) {
    $this->setContext();
    $isRecur = CRM_Utils_Array::value('is_recur', $params) && $params['is_recur'];
    if (!$isRecur) {
      throw new RuntimeException('Can only handle recurring payments');
    }

    $provider = PaymentProviderFactory::getProviderForMethod('cc');

    $processorId = $this->createPayment($params, $provider);
    $approvePaymentResponse = $provider->approvePayment($processorId, []);
    if (!empty($approvePaymentResponse['errors'])) {
      $this->throwException(
        'ApprovePayment failed', $approvePaymentResponse['errors']
      );
    }
    $allContributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $status = array_search('Completed', $allContributionStatus);
    return [
      'processor_id' => $processorId,
      'invoice_id' => $params['invoice_id'],
      'payment_status_id' => $status,
    ];
  }

  protected function setContext() {
    $tag = $this->_paymentProcessor['name'];
    // Needs to be one of the payment processor tags listed in the SmashPig
    // processor config folder
    CRM_SmashPig_ContextWrapper::createContext("processor_$tag", $tag);
    // TOTHINKABOUT: here we could override ProcessorConfiguration values to use
    // credentials from the Civi UI.
  }

  /**
   * support corresponding CiviCRM method
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    throw new NotImplementedException();
  }

  /**
   * Copied this over from the corresponding Omnipay class....
   */
  public function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(),
        0, NULL,
        $error->getMessage()
      );
    }
    elseif ($error && is_numeric($error)) {
      $e->push($error,
        0, NULL,
        $this->errorString($error)
      );
    }
    elseif (is_string($error)) {
      $e->push(9002,
        0, NULL,
        $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
  }

  /**
   * Convert CiviCRM params to SmashPig params
   *
   * @param array $params
   *
   * @return array
   */
  protected function convertParams($params) {
    $request = [];
    $convert = [
      'token' => 'recurring_payment_token',
      'amount' => 'amount',
      'currency' => 'currency',
      'first_name' => 'first_name',
      'last_name' => 'last_name',
      'email' => 'email',
      'address' => 'street_address',
      'city' => 'city',
      'state' => 'state_province',
      'zipCode' => 'postal_code',
      'country' => 'country',
      'invoice_id' => 'order_id',
      'installment' => 'installment',
      'description' => 'description',
      'is_recur' => 'recurring',
      'ip_address' => 'user_ip',
    ];
    foreach ($convert as $civiName => $smashPigName) {
      if (array_key_exists($civiName, $params)) {
        $request[$smashPigName] = $params[$civiName];
      }
    }
    return $request;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    return FALSE;
  }

  /**
   * Create a payment attempt at the payment processor
   *
   * @param array $params
   * @param $provider
   *
   * @return string The processor's ID for the payment
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  protected function createPayment($params, $provider) {
    $request = $this->convertParams($params);
    $createPaymentResponse = $provider->createPayment($request);
    if (!empty($createPaymentResponse['errors'])) {
      $this->throwException(
        'CreatePayment Failed', $createPaymentResponse['errors']
      );
    }
    // FIXME: SmashPig library should normalize return values, this is
    // specific to Ingenico Connect's json response
    $trxnId = $createPaymentResponse['payment']['id'];
    return $trxnId;
  }


  /**
   * Format and throw a PaymentProcessorException, given an array of
   * errors from the SmashPig payment call.
   *
   * @param string $message
   * @param array[] $errorArray
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  protected function throwException($message, $errorArray) {
    $errorCode = -1;
    if ( count( $errorArray ) === 1 ) {
      $error = $errorArray[0];
      if (isset($error['message'])) {
        $message .= ': ' . $error['message'];
      }
      if (isset($error['code'])) {
        $errorCode = intval($error['code']);
      }
    }
    throw new PaymentProcessorException(
      $message, $errorCode, $errorArray
    );
  }
}

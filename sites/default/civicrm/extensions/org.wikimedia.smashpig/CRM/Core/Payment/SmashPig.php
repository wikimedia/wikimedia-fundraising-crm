<?php

use Civi\API\Exception\NotImplementedException;
use Civi\Payment\Exception\PaymentProcessorException;
use SmashPig\PaymentProviders\PaymentProviderFactory;

require_once "CRM/SmashPig/ContextWrapper.php";

class CRM_Core_Payment_SmashPig extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable.
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

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
   *
   */
  static public function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new static($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
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
   * @return array with trxn_id set to the processor's payment ID
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doDirectPayment(&$params) {
    $this->setContext();
    $isRecur = CRM_Utils_Array::value('is_recur', $params) && $params['is_recur'];
    if (!$isRecur) {
      throw new RuntimeException('Can only handle recurring payments');
    }

    $provider = PaymentProviderFactory::getProviderForMethod('cc');

    $request = $this->convertParams($params);
    $createPaymentResponse = $provider->createPayment($request);
    if (!empty($createPaymentResponse['errors'])) {
      throw new PaymentProcessorException(
        'CreatePayment Failed: ' . print_r($createPaymentResponse['errors'], TRUE)
      );
    }
    // FIXME: SmashPig library should normalize return values, this is
    // specific to Ingenico Connect's json response
    $trxnId = $createPaymentResponse['payment']['id'];
    $approvePaymentResponse = $provider->approvePayment($trxnId, []);
    if (!empty($approvePaymentResponse['errors'])) {
      throw new PaymentProcessorException(
        'ApprovePayment Failed: ' . print_r($approvePaymentResponse['errors'], TRUE)
      );
    }
    $allContributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $status = array_search('Completed', $allContributionStatus);
    return [
      'trxn_id' => $trxnId,
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
}

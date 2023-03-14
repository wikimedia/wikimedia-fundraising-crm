<?php

use Civi\Payment\Exception\PaymentProcessorException;
use SmashPig\PaymentData\ErrorCode;
use SmashPig\PaymentData\FinalStatus;
use SmashPig\PaymentProviders\PaymentProviderFactory;
use SmashPig\PaymentProviders\Responses\CreatePaymentResponse;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\PaymentProviderResponse;

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
   * The intention is to support off-site (other than paypal) & direct debit
   * but that is not all working yet so to reach a 'stable' point we disable.
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
   * @param string $component
   *   Unused parameter, could be used to construct a url to an event page (although it
   *   could be intuited by the params anyway).
   *
   * @return array with processor_id set to the processor's payment ID
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CRM_Core_Exception
   */
  public function doPayment(&$params, $component = 'contribute'): array {
    $completedStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    if ((float) $this->getAmount($params) === 0.0) {
      $result['payment_status_id'] = $completedStatusID;
      $result['payment_status'] = 'Completed';
      return $result;
    }
    $this->setContext();
    $isRecur = CRM_Utils_Array::value('is_recur', $params) && $params['is_recur'];
    if (!$isRecur) {
      throw new RuntimeException('Can only handle recurring payments');
    }

    $paymentMethod = self::getPaymentMethod($params);

    $provider = PaymentProviderFactory::getProviderForMethod($paymentMethod);

    $request = $this->convertParams( $params );
    /** @var CreatePaymentResponse $createPaymentResponse */
    $createPaymentResponse = $provider->createPayment( $request );

    if (!$createPaymentResponse->isSuccessful()) {
      $this->throwException( 'CreatePayment failed', $createPaymentResponse );
    }
    $gatewayTxnId = $createPaymentResponse->getGatewayTxnId();

    if ($createPaymentResponse->getStatus() === FinalStatus::PENDING_POKE) {
      /** @var ApprovePaymentResponse $approvePaymentResponse */
      $approvePaymentResponse = $provider->approvePayment([
        'amount' => $params['amount'],
        'currency' => $params['currency'],
        'gateway_txn_id' => $gatewayTxnId,
      ]);

      if (!$approvePaymentResponse->isSuccessful()) {
        $this->throwException('ApprovePayment failed', $approvePaymentResponse);
      }

    }

    return [
      'processor_id' => $gatewayTxnId,
      'invoice_id' => $params['invoice_id'],
      'payment_status_id' => $completedStatusID,
      'payment_status' => 'Completed',
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
      'recurring_invoice_id' => 'processor_contact_id',
      'legal_identifier' => 'fiscal_number'
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
   * Format and throw a PaymentProcessorException, given an array of
   * errors from the SmashPig payment call.
   *
   * @param string $errorMessage
   * @param PaymentProviderResponse $processorResponse
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  protected function throwException( $errorMessage, PaymentProviderResponse $processorResponse ) {
    if (!$processorResponse->hasErrors()) {
      // Response has neither PaymentErrors nor ValidationErrors
      $errorCode = ErrorCode::UNKNOWN;
    }
    elseif ($processorResponse->hasError(ErrorCode::DECLINED_DO_NOT_RETRY)) {
      $errorCode = ErrorCode::DECLINED_DO_NOT_RETRY;
    }
    else {
      $paymentErrors = $processorResponse->getErrors();
      if ( empty( $paymentErrors ) ) {
        // If hasErrors is true and getErrors returns an empty array, we must have
        // a validation error.
        $errorCode = ErrorCode::VALIDATION;
      } else {
        $errorCode = $processorResponse->getErrors()[0]->getErrorCode();
      }
    }
    $errorData = [
      'smashpig_processor_response' => $processorResponse
    ];
    throw new PaymentProcessorException(
      $errorMessage, $errorCode, $errorData
    );
  }

  public function getEditableRecurringScheduleFields() {
    return ['amount', 'cycle_day', 'next_sched_contribution_date'];
  }

  public function supportsEditRecurringContribution() {
    return true;
  }

  /**
   * Get the SmashPig payment method corresponding to the payment attempt.
   *
   * @param array $params
   *
   * @return string
   */
  public static function getPaymentMethod(array $params) {
    switch ($params['payment_instrument']) {
      case 'iDeal':
        return 'rtbt';
      case 'Paypal';
        return 'paypal';
      case 'Bank Transfer: UPI':
        return 'bt';
      default:
        return 'cc';
    }
  }
}

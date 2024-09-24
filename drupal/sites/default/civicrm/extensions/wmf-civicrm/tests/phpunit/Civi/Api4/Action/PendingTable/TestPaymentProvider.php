<?php

namespace Civi\Api4\Action\PendingTable;

use SmashPig\PaymentProviders\ICancelablePaymentProvider;
use SmashPig\PaymentProviders\IGetLatestPaymentStatusProvider;
use SmashPig\PaymentProviders\IPaymentProvider;
use SmashPig\PaymentProviders\Responses\ApprovePaymentResponse;
use SmashPig\PaymentProviders\Responses\CancelPaymentResponse;
use SmashPig\PaymentProviders\Responses\CreatePaymentResponse;
use SmashPig\PaymentProviders\Responses\PaymentDetailResponse;

class TestPaymentProvider implements IPaymentProvider, IGetLatestPaymentStatusProvider, ICancelablePaymentProvider {

  /**
   * Implement the methods from both interfaces. The bodies can be empty since we're mocking them.
   */
  public function createPayment(array $params) : CreatePaymentResponse {}

  public function approvePayment(array $params) : ApprovePaymentResponse {}

  public function getLatestPaymentStatus(array $params): PaymentDetailResponse {}

  public function cancelPayment(string $gatewayTxnId) : CancelPaymentResponse {}

}

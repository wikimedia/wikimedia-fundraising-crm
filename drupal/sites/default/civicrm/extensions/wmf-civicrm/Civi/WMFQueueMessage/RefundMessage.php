<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;
use Civi\Api4\Name;
use Civi\Api4\PaymentToken;
use Civi\ExchangeRates\ExchangeRatesException;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFHelper\FinanceInstrument;
use CRM_Core_Exception;

// It may make sense for this to extend Donation Message but let's start
// with only what we need.
class RefundMessage extends Message {

}

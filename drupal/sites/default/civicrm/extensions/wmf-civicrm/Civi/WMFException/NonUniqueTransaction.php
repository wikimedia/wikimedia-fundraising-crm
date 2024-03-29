<?php

namespace Civi\WMFException;

use Civi\WMFTransaction;

class NonUniqueTransaction extends WMFException {
  function __construct(WMFTransaction $transaction) {
    parent::__construct( "GET_CONTRIBUTION", "Transaction does not resolve to a single contribution: {$transaction->get_unique_id()}" );
  }
}

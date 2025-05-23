<?php

namespace Civi\WMFException;
use Civi\WMFTransaction;

class NoTransactionExists extends WMFException {
  function __construct(WMFTransaction $transaction) {
    parent::__construct( "GET_CONTRIBUTION", "No such transaction: {$transaction->get_unique_id()}" );
  }
}

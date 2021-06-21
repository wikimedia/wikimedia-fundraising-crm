<?php

namespace Civi\WMFException;

class NoTransactionExists extends WMFException {
  function __construct(\WmfTransaction $transaction) {
    parent::__construct( "GET_CONTRIBUTION", "No such transaction: {$transaction->get_unique_id()}" );
  }
}

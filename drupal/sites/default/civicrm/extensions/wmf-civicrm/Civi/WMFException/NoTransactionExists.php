<?php

namespace Civi\WMFException;

use \WmfException;

class NoTransactionExists extends WmfException {
  function __construct(\WmfTransaction $transaction) {
    parent::__construct( "GET_CONTRIBUTION", "No such transaction: {$transaction->get_unique_id()}" );
  }
}

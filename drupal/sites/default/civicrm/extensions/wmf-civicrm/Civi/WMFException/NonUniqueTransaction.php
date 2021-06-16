<?php

namespace Civi\WMFException;

class NonUniqueTransaction extends WMFException {
  function __construct(\WmfTransaction $transaction) {
    parent::__construct( "GET_CONTRIBUTION", "Transaction does not resolve to a single contribution: {$transaction->get_unique_id()}" );
  }
}

<?php

namespace Civi\WMFException;
use WMFException;
use WmfTransaction;

class AlreadyRecurring extends WmfException {
  function __construct(WmfTransaction $transaction) {
    parent::__construct( "DUPLICATE_CONTRIBUTION", "Already a recurring contribution: {$transaction->get_unique_id()}" );
  }
}

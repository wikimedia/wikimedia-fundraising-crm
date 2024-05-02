<?php

namespace Civi\ExchangeRates;

class UpdateResult {
  /**
   * @var array key is currency code, value is array with two keys:
   *	'value' = USD value of a single unit, 'date' = UTC timestamp
   */
  public $rates = array();
  /**
   * @var int number of quotes remaining
   */
  public $quotesRemaining = -1;
}

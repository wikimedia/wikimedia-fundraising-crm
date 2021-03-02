<?php
namespace exchange_rates;

use \Exception;
use \InvalidArgumentException;

abstract class ExchangeRateRetriever {
	protected $httpRequester;

	/**
	 * @param callable $httpRequester - either drupal_http_request or a fake
	 * @throws InvalidArgumentException
	 */
	public function __construct( $httpRequester ) {
		if ( !is_callable( $httpRequester ) ) {
			throw new InvalidArgumentException( 'httpRequester should be callable' );
		}
		$this->httpRequester = $httpRequester;
	}

	/**
	 * Retrieve updated rates using $this->httpRequester
	 * @param array $currencies - list of currency codes to update
	 * @param DateTime $date - retrieve rates for this date.  If omitted, get latest rates.
	 * @return ExchangeRateUpdateResult
	 */
	abstract function updateRates( $currencies, $date = null );
}

class ExchangeRateUpdateResult {
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

class ExchangeRateUpdateException extends Exception {}

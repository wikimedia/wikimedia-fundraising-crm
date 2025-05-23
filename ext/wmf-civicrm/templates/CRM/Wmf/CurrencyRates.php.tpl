<?php
/**
 * Automatically generated from wmf-civicrm/{$templatePath}
 * -- do not edit! --
 * Instead, run cv api4 ExchangeRate.exportToClass outputDirectory=/output/dir and look in the specified folder.
 */
namespace SmashPig\PaymentData\ReferenceData;

class CurrencyRates {
	/**
	 * Supplies rough (not up-to-date) conversion rates for currencies
	 */

	public static $lastUpdated = '{$lastUpdated}';

	public static function getCurrencyRates() {
		// Not rounding numbers under 1 because I don't think that's a big issue and could cause issues with the max check.
		$currencyRates = [
{foreach from=$rates item=rate}
			'{$rate.currency}' => {$rate.unitsInOneDollar},
{/foreach}
		];
		return $currencyRates;
	}
}

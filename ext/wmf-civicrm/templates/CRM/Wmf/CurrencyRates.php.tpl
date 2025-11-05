<?php
/**
 * Automatically generated from wmf-civicrm/{$templatePath}
 * -- do not edit! --
 * Instead, run cv api4 ExchangeRate.exportToClass outputDirectory=/output/dir and look in the specified folder.
 */
namespace SmashPig\PaymentData\ReferenceData;

/**
 * Supplies rough (not up-to-date) conversion rates for currencies
 */
class CurrencyRates {

	public static string $lastUpdated = '{$lastUpdated}';

	public static function getCurrencyRates(): array {
		// Not rounding numbers under 1 because I don't think that's a big issue and could cause issues with the max check.
		return [
{foreach from=$rates item=rate}
			'{$rate.currency}' => {$rate.unitsInOneDollar},
{/foreach}
		];
	}
}

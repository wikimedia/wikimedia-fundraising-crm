<?php


/**
 *
 *
 * Smarty plugin to allow for using l10n to display currencies
 *
 *
 */

/**
 * Format the given monetary amount (and currency) for display
 *
 * @param float $amount
 *   The monetary amount up for display.
 * @param string $currency
 *   The currency.
 *
 * @return string
 *   formatted monetary amount
 */
function smarty_modifier_extCurrency($amount, $currency) {
  civicrm_initialize();
  $currency_symbol = CRM_CORE_DAO::singleValueQuery("SELECT symbol FROM civicrm_currency WHERE name=%1", array(1 => array($currency, 'String')));
  return "$currency_symbol $amount";
}

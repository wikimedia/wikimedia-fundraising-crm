<?php
/**
 * Implementation of hook_drush_command()
 */
function exchange_rates_drush_command(): array {
  $items['exchange-rates-update'] = [
    'description' => 'Updates exchange rates from provider.',
    'examples' => ['drush exchange-rates-update'],
  ];
  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function exchange_rates_drush_help($section) {
  if ($section === 'drush:exchange-rates-update') {
    return dt('Attempts to retrieve the latest exchange rates from a providers, stopping when a request succeeds and storing results in the database.');
  }
}

function drush_exchange_rates_update() {
  module_invoke('exchange_rates', 'update_all');
}

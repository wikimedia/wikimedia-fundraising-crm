<?php
/**
 * Detect and rectify recently orphaned Gateway transactions
 */

/**
 * Implementation of hook_drush_command()
 */
function orphan_slayer_drush_command() {
  $items = [];

  $items['orphan-slayer'] = [
    'description' =>
      'Check for orphaned Gateway transactions, and resolve.',
    'examples' => [
      'drush orphan-slay' => '# Run the orphan rectifier.',
    ],
    'required-arguments' => 'true',
    'arguments' => [
      'gateway' => 'gateway',
    ],
    'options' => [
      'age' => 'Minimum age in minutes for a donation to be considered orphaned (defaults to 30)',
      'batch' => 'Number of contributions to process. If set to zero, it will be treated as unlimited',
      'time_limit' => 'Time limit in seconds for job',
    ],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function orphan_slayer_drush_help($section) {
  switch ($section) {
    case 'orphan_slayer':
      return dt("Check for orphaned transactions, and resolve.");
  }
}

/**
 * Note: You'll need to include a bunch of exciting MediaWiki globals in your
 * Drupal settings.local.php, including default values that would normally be
 * read from extension.json.
 *     $wgDonationInterfaceGatewayAdapters
 *     $wgDonationInterfaceForbiddenCountries
 *     $wgDonationInterface3DSRules
 *     $wgDonationInterfacePriceFloor
 *     $wgDonationInterfacePriceCeiling
 *     $wgDonationInterfaceRetryLoopCount
 * For any gateways implemented, set these globals, replacing name with the
 * gateway identifier
 *     $wg[name]GatewayEnabled
 *     $wg[name]GatewayAccountInfo
 *     $wg[name]GatewayURL
 */
function drush_orphan_slayer() {
  // TODO: SmashPig and DI initialization should be reused from a higher
  // level and integrated with app config
  $args = drush_get_arguments();
  $gateway = $args[1];
  $age = intval(drush_get_option('age', 30));
  $batch = intval(drush_get_option('batch', 0));
  $timeLimit = intval(drush_get_option('time_limit', 0));

  wmf_common_create_smashpig_context("gateway_orphan_slayer", "$gateway");
  orphan_slayer_process_orphans($gateway, $age, $batch, $timeLimit);
}

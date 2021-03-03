<?php
/**
 * Detect and rectify recently orphaned Gateway transactions
 */

/**
 * Implementation of hook_drush_command()
 */
function orphan_slayer_drush_command() {
    $items = array();

    $items['orphan-slayer'] = array(
        'description' =>
            'Check for orphaned Gateway transactions, and resolve.',
        'examples' => array(
            'drush orphan-slay' => '# Run the orphan rectifier.',
        ),
        'required-arguments' => 'true',
        'arguments' => array(
            'gateway' => 'gateway',
        ),
        'options' => array(
            'time' => 'time'
        )
    );

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
 * For any gateways implemented, set these globals, replacing name with the gateway identifier
 *     $wg[name]GatewayEnabled
 *     $wg[name]GatewayAccountInfo
 *     $wg[name]GatewayURL

 */
function drush_orphan_slayer() {
    // TODO: SmashPig and DI initialization should be reused from a higher
    // level and integrated with app config
    $args = drush_get_arguments();
    $gateway = $args[1];
    $time = drush_get_option('time', '30');

    wmf_common_create_smashpig_context("gateway_orphan_slayer", "$gateway");
    orphan_slayer_process_orphans($gateway, $time);
}

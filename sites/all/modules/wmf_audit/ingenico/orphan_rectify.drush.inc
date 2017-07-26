<?php
/**
 * Detect and rectify recently orphaned Ingenico transactions
 */

/**
 * Implementation of hook_drush_command()
 */
function orphan_rectify_drush_command() {
	$items = array();

	$items['orphan-rectify'] = array(
		'description' =>
			'Check for orphaned Ingenico transactions, and resolve.',
		'examples' => array(
			'drush orphan-rectify' => '# Run the orphan rectifier.',
		),
		'options' => array(
		),
	);

	return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function orphan_rectify_drush_help($section) {
	switch ($section) {
		case 'orphan-rectify':
			return dt("Check for orphaned Ingenico transactions, and resolve.");
	}
}

/**
 * Note: You'll need to include a bunch of exciting MediaWiki globals in your
 * Drupal settings.local.php, including default values that would normally be
 * read from extension.json.
 *     $wgDonationInterfaceDefaultQueueServer
 *     $wgDonationInterfaceQueues
 *     $wgDonationInterfaceEnableQueue
 *     $wgDonationInterfaceQueueMirrors
 *     $wgDonationInterfaceOrphanCron
 *     $wgDonationInterfaceGatewayAdapters
 *     $wgDonationInterfaceForbiddenCountries
 *     $wgDonationInterface3DSRules
 *     $wgGlobalCollectGatewayEnabled
 *     $wgGlobalCollectGatewayAccountInfo
 *     $wgGlobalCollectGatewayURL
 *     $wgDonationInterfacePriceFloor
 *     $wgDonationInterfacePriceCeiling
 *     $wgDonationInterfaceRetryLoopCount
 */
function drush_orphan_rectify() {
	// TODO: SmashPig and DI initialization should be reused from a higher
	// level and integrated with app config
	wmf_common_create_smashpig_context( 'ingenico_orphan_rectifier', 'ingenico' );

	$rectifier = new GlobalCollectOrphanRectifier();
	$rectifier->processOrphans();
}

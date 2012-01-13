<?php

civicrm_initialize();

function get_payment_instrument( $name ){

	$payment_instrument = civicrm_api( "OptionValue", "Get", array(
		"version" => '3',
		"option_group_name" => 'payment_instrument',
		"label" => $name
	) );
	# check for API errors and log the action
	if( is_array( $payment_instrument ) && array_key_exists( 'is_error', $payment_instrument ) && $payment_instrument['is_error'] == "0" ) {
		# valid API result
		if( array_key_exists( "id", $payment_instrument ) && array_key_exists( "values", $payment_instrument )
				&& is_array( $payment_instrument['values'] ) && array_key_exists( strval( $payment_instrument['id'] ),
			$payment_instrument['values'] )
		) {
			$payment_instrument = $payment_instrument['values'][$payment_instrument['id']];
			watchdog( 'offline2civicrm', "Payment instrument found for name: " . $payment_instrument['label'], array(), WATCHDOG_INFO );
		} else {
			watchdog( 'offline2civicrm', "Payment instrument not found for $name: ", array(), WATCHDOG_ERROR );
			throw new Exception();
		}
	} else {
		watchdog( 'offline2civicrm', "API Error while retrieving payment instrument: " . print_r( $payment_instrument ), array(), WATCHDOG_ERROR );
		throw new Exception();
	}

	return $payment_instrument;
}

/**
 * Retrieves an object representing the contribution type specified
 *
 * @todo Add error checking
 * @todo Implement ContributionType in CiviCRM API
 *
 * @param $name The name of the contribution type to retrieve
 * @return CRM_Contribute_BAO_ContributionType|object
 */
function get_contribution_type( $type ){
	# ContributionType is not implemented in the API
	require_once( DRUPAL_ROOT . '/sites/all/modules/civicrm/CRM/Contribute/BAO/ContributionType.php' );

	$p = array( "accounting_code" => $type );
	$q = array();
	$contribution_type = CRM_Contribute_BAO_ContributionType::retrieve( $p, $q );

	return $contribution_type;
}





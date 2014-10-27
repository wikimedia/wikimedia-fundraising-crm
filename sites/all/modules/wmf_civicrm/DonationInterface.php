<?php

class DonationInterface {
    static public function createAdapter( $type, $data ) {
        // Configure DonationInterface according to Drupal settings.
        $adapterOptions = array(
            'batch_mode' => true,
            'external_data' => $data,

            // Avoid Title code in GlobalCollectAdapter::setGatewayDefaults().
            'returnTitle' => 'dummy',
            // Unnecessary avoidance of wfAppendQuery call in stage_returnto,
            // which should never be hit anyway cos returnto is not in these APIs.
            'returnTo' => 'dumber',
        );

        // Yeah, I know... this is a consequence of not running the main
        // initializations in the extension's DonationInterface.php.  We
        // could clean it up by moving initialization to a function which
        // is safe to call from Drupal.
        global $wgDonationInterfaceForbiddenCountries,
            $wgDonationInterfacePriceFloor,
            $wgDonationInterfacePriceCeiling,
            $wgGlobalCollectGatewayAccountInfo,
            $wgGlobalCollectGatewayURL,
            $wgGlobalCollectGatewayMerchantID;

        // Adapt Drupal configuration into MediaWiki globals.
        $wgGlobalCollectGatewayMerchantID = variable_get('recurring_globalcollect_merchant_id', 0);

        $wgGlobalCollectGatewayAccountInfo['default'] = array(
            'MerchantID' => $wgGlobalCollectGatewayMerchantID,
        );

        $wgGlobalCollectGatewayURL = variable_get( 'globalcollect_url', '' );

        $wgDonationInterfaceForbiddenCountries = array();

        $wgDonationInterfacePriceFloor = 1.00;
        $wgDonationInterfacePriceCeiling = 10000.00;

        $className = "{$type}Adapter";
        $adapter = new $className( $adapterOptions );
        return $adapter;
    }
}

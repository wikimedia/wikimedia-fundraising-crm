<?php

class DonationInterface {
    static public function getAdapter( $type, $options = array() ) {
        // TODO: Doesn't DI have this already:
        // switch ( $type ) {
        // case 'GlobalCollect':
        // default:
        //     throw new WmfException( 'CIVI_CONFIG', 'Unknown gateway adapter requested: ' . $type );

        // Configure DonationInterface according to Drupal settings.
        // TODO: make variable names less crazy
        $adapterOptions = array();

        global $wgGlobalCollectGatewayMerchantID;
        $wgGlobalCollectGatewayMerchantID = variable_get('recurring_globalcollect_merchant_id', 0);

        global $wgGlobalCollectGatewayURL;
        $wgGlobalCollectGatewayURL = variable_get( 'globalcollect_url', '' );

        $standalone_globalcollect_adapter_path = variable_get('standalone_globalcollect_adapter_path', null);
        $path = implode( DIRECTORY_SEPARATOR, array(
            $standalone_globalcollect_adapter_path,
            'globalcollect.adapter.php'
        ) );
        require_once $path;

        return new GlobalCollectAdapter( $options );
    }
}

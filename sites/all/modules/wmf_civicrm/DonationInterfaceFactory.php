<?php

class DonationInterfaceFactory {
    static public function createAdapter( $gatewayName, $data ) {
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

        $className = DonationInterface::getAdapterClassForGateway( $gatewayName );
        $adapter = new $className( $adapterOptions );
        return $adapter;
    }
}

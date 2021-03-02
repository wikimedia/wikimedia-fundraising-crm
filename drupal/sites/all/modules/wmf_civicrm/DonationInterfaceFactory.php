<?php

class DonationInterfaceFactory {
    /**
     * @param string $gatewayName
     * @param array $data
     * @return GatewayType
     */
    static public function createAdapter( $gatewayName, $data ) {
        // Configure DonationInterface according to Drupal settings.
        // FIXME lame country default. We really should know the country
        // context when we're doing payment things.
        if ( empty( $data['country'] ) ) {
            $data['country'] = 'US';
        }
        $adapterOptions = array(
            'batch_mode' => true,
            'external_data' => $data,
            // Unnecessary avoidance of wfAppendQuery call in stage_returnto,
            // which should never be hit anyway cos returnto is not in these APIs.
            'returnTo' => 'dummy',
        );

        $className = DonationInterface::getAdapterClassForGateway( $gatewayName );
        $adapter = new $className( $adapterOptions );
        return $adapter;
    }
}

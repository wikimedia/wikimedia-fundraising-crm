<?php

use SmashPig\Core\DataStores\DamagedDatabase;
use SmashPig\Core\DataStores\PendingDatabase;

class OrphanSlayer {
    public $gateway;
    protected $adapter;

    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    public function get_oldest() {
        return PendingDatabase::get()->fetchMessageByGatewayOldest($this->gateway);
    }

    public function rectify($orphan) {
        $orphan['amount'] = $orphan['gross'];
        $this->adapter = DonationInterfaceFactory::createAdapter($this->gateway, $orphan);
        $result = array();
        try {
            $result = $this->adapter->rectifyOrphan();
        } catch ( Exception $e ) {
            DamagedDatabase::get()->storeMessage( $orphan, 'pending', $e->getMessage(), $e->getTraceAsString() );
        }
        $this->delete_orphan($orphan);
        return $result;
    }

    public function cancel($orphan) {
        $this->adapter = DonationInterfaceFactory::createAdapter($this->gateway, $orphan);
        $this->adapter->cancel();
        $this->delete_orphan($orphan);
    }

    protected function delete_orphan($orphan) {
        PendingDatabase::get()->deleteMessage($orphan);
    }

}

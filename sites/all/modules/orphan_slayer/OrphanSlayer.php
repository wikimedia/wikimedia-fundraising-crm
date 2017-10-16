<?php

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
        $result = $this->adapter->rectifyOrphan();
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

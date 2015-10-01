<?php

class WmfCampaign {
    protected $key;
    protected $notification_email;

    protected function __construct() {}

    /**
     * @return WmfCampaign|null
     */
    public static function fromKey( $key ) {
        try {
            $result = db_select( 'wmf_campaigns_campaign' )
                ->fields( 'wmf_campaigns_campaign' )
                ->condition( 'campaign_key', $key )
                ->execute()
                ->fetchAssoc();
        } catch ( CiviCRM_API3_Exception $ex ) {
            watchdog( 'wmf_campaigns', "Couldn't find campaign {$key}: " . $ex->getMessage(), NULL, WATCHDOG_WARNING );
            return null;
        }
        return WmfCampaign::fromDbRecord( $result );
    }

    protected static function fromDbRecord( $record ) {
        $camp = new WmfCampaign();
        $camp->key = $record['campaign_key'];
        $camp->notification_email = $record['notification_email'];
        return $camp;
    }

    public function getKey() {
        return $this->key;
    }

    public function getNotificationEmail() {
        return $this->notification_email;
    }
}

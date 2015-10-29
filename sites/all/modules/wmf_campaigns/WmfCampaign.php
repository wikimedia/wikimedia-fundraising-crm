<?php

class WmfCampaign {
    protected $key;
    protected $notification_email;

    protected function __construct() {}

    /**
     * @return WmfCampaign|null
     */
    public static function fromKey( $key ) {
        $result = db_select( 'wmf_campaigns_campaign' )
            ->fields( 'wmf_campaigns_campaign' )
            ->condition( 'campaign_key', $key )
            ->execute()
            ->fetchAssoc();

		if ( $result === false ) {
			throw new CampaignNotFoundException( "Campaign {$key} is missing WMF Campaign info." );
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

class CampaignNotFoundException extends RuntimeException {
}

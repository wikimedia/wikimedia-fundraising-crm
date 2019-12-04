<?php

class WmfCampaign {

  protected $key;

  protected $notification_email;

  protected function __construct() {
  }

  /**
   * @param string $key
   *
   * @return WmfCampaign
   */
  public static function fromKey($key) {
    if (!isset(Civi::$statics['wmf_campaigns']['campaigns'])) {
      Civi::$statics['wmf_campaigns']['campaigns'] = [];
      $result = db_select('wmf_campaigns_campaign', 'c', ['fetch' => PDO::FETCH_ASSOC])
        ->fields('c')
        ->execute();
      foreach ($result as $record) {
        Civi::$statics['wmf_campaigns']['campaigns'][$record['campaign_key']] = self::fromDbRecord($record);
      }
    }
    if (!isset(Civi::$statics['wmf_campaigns']['campaigns'][$key])) {
      // FIXME not exceptional - this is the case more often than not
      throw new CampaignNotFoundException("Campaign {$key} is missing WMF Campaign info.");
    }
    return Civi::$statics['wmf_campaigns']['campaigns'][$key];
  }

  protected static function fromDbRecord($record) {
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

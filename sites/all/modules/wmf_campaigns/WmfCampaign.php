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
        Civi::$statics['wmf_campaigns']['campaigns'][$record['campaign_key']] = $record['notification_email'];
      }
    }
    if (!isset(Civi::$statics['wmf_campaigns']['campaigns'][$key])) {
      // FIXME not exceptional - this is the case more often than not
      // maybe just return NULL .... just sayin'
      throw new CampaignNotFoundException("Campaign {$key} is missing WMF Campaign info.");
    }
    return Civi::$statics['wmf_campaigns']['campaigns'][$key];
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

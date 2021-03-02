<?php

class WmfCampaign {

  /**
   * @param string $key
   *
   * @return string comma-separated list of email addresses to notify
   */
  public static function getNotificationAddressesFromKey($key) {
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
      return NULL;
    }
    return Civi::$statics['wmf_campaigns']['campaigns'][$key];
  }
}

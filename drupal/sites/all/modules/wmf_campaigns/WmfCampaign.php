<?php

class WmfCampaign {

  /**
   * @param string $key
   *
   * @return array|null
   */
  public static function getNotificationAddressesFromKey(string $key): ?array {
    if (!isset(Civi::$statics['wmf_campaigns']['campaigns'])) {
      Civi::$statics['wmf_campaigns']['campaigns'] = [];
      $result = db_select('wmf_campaigns_campaign', 'c', ['fetch' => PDO::FETCH_ASSOC])
        ->fields('c')
        ->execute();
      foreach ($result as $record) {
        $emails = explode(',', $record['notification_email']);
        array_walk($emails, 'trim');
        Civi::$statics['wmf_campaigns']['campaigns'][$record['campaign_key']] = $emails;
      }
    }
    if (!isset(Civi::$statics['wmf_campaigns']['campaigns'][$key])) {
      return NULL;
    }
    return Civi::$statics['wmf_campaigns']['campaigns'][$key];
  }
}

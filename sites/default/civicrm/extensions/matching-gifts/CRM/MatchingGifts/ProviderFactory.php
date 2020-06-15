<?php

class CRM_MatchingGifts_ProviderFactory {
  public static function getProvider($providerName) {
    $credentials = Civi::settings()->get(
      self::fullSettingName('credentials', $providerName)
    );
    $className = 'CRM_MatchingGifts_' . ucfirst($providerName) . 'Provider';
    return new $className($credentials);
  }

  public static function getFetchDefaults($providerName) {
    $settings = Civi::settings();
    return [
      'lastUpdated' => $settings->get(
        self::fullSettingName('last_updated', $providerName)
      ),
      'matchedCategories' => $settings->get(
        self::fullSettingName('matched_categories', $providerName)
      )
    ];
  }

  public static function fullSettingName($setting, $providerName) {
    return "matchinggifts.{$providerName}_{$setting}";
  }
}

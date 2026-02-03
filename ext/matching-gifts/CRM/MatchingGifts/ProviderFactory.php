<?php

class CRM_MatchingGifts_ProviderFactory {
  public static function getProvider(string $providerName): CRM_MatchingGifts_ProviderInterface {
    $credentials = Civi::settings()->get(
      self::fullSettingName('credentials', $providerName)
    );
    $className = 'CRM_MatchingGifts_' . ucfirst($providerName) . 'Provider';
    return new $className($credentials);
  }

  public static function getFetchDefaults(string $providerName): array {
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

  public static function fullSettingName(string $setting, string $providerName): string {
    return "matchinggifts.{$providerName}_{$setting}";
  }
}

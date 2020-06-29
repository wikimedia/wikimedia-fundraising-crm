<?php

class CRM_MatchingGifts_ProviderFactory {
  public static function getProvider($providerName) {
    $credentials = Civi::settings()->get(
      "matchinggifts.{$providerName}_credentials"
    );
    $className = 'CRM_MatchingGifts_' . ucfirst($providerName) . 'Provider';
    return new $className($credentials);
  }

  public static function getFetchDefaults($providerName) {
    $settings = Civi::settings();
    return [
      'lastUpdated' => $settings->get(
        "matchinggifts.{$providerName}_last_updated"
      ),
      'matchedCategories' => $settings->get(
        "matchinggifts.{$providerName}_matched_categories"
      )
    ];
  }
}

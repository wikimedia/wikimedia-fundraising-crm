<?php

namespace wmf_communication;

/**
 * Helper for language tag manipulation and a rudimentary MediaWiki i18n facade
 *
 * TODO: deprecate
 */
class Translation {

  /**
   * Given a specific locale, get the next most general locale
   *
   * TODO: get from LanguageTag library and refactor interface
   */
  static function next_fallback($language) {
    $parts = preg_split('/[-_]/', $language);
    if (count($parts) > 1) {
      return $parts[0];
    }
    if ($language === 'en') {
      return NULL;
    }
    return 'en';
  }

}


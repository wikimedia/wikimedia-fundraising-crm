<?php

namespace wmf_communication;

/**
 * Helper for language tag manipulation and a rudimentary MediaWiki i18n facade
 *
 * TODO: deprecate
 */
class Translation {

  /**
   * Replaces tokens in a string with a translation
   *
   * Tokens are specified using the MediaWiki message key, and are delimited by
   * "%".
   *
   * For example:
   *   $locale = 'fr';
   *   $template = '<p
   * id="unsub-text">%donate_interface-email-unsub-fail%</p>';
   *   $rendered = $l10n->replace_messages($template, $locale);
   *   -->  <p id="unsub-text">Vous avez le fail, foo.</p>
   *
   * @param string $string The string to replace tokens in
   * @param string $language The ISO-2 language code
   *
   * @return mixed                The resultant natural language string
   */
  static function replace_messages($string, $language = 'en') {
    $messages = MediaWikiMessages::getInstance();

    // search for messages in the source file like %message_token% and, optionally,
    // like %message_token|param1|param2%
    $matches = [];
    preg_match_all("/%([a-zA-Z0-9_-]+)(|(?:(?!%).)*)%/", $string, $matches);

    // loop through the found tokens and replace with messages, if they exist
    foreach ($matches[1] as $i => $msg_key) {
      // look for parameters passed to the message
      if (isset($matches[2][$i]) && $matches[2][$i] != '') {
        $m = $messages->getMsg($matches[1][$i], $language);
        $params = explode('|', trim($matches[2][$i], '|'));
        foreach ($params as $k => $value) {
          $k++; // params are 1-indexed
          $m = str_replace("\$$k", $value, $m);
        }
        $string = str_replace($matches[0][$i], $m, $string);
      }
      else {
        $string = str_replace($matches[0][$i], $messages->getMsg($matches[1][$i], $language), $string);
      }
    }

    return $string;
  }

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

  /**
   * Fetch a MediaWiki message translated in the DonationInterface group
   *
   * @param string $key message key
   * @param string $language MediaWiki language code
   *
   * @return string message contents
   *
   * TODO: No wikitext expansion?
   * TODO: accept standard language tag and convert to MW
   * TODO: generalize beyond DonationInterface
   */
  static function get_translated_message($key, $language) {
    $di_i18n = MediaWikiMessages::getInstance();
    do {
      $msg = $di_i18n->getMsg($key, $language);
      if ($msg) {
        return $msg;
      }
      $language = self::next_fallback($language);
    } while ($language);
  }

  /**
   * Convert unix locale to a two-digit language code
   *
   * TODO: the cheeze
   */
  static function normalize_language_code($code) {
    $locale = explode('_', $code);
    if (count($locale) == 0) {
      // TODO: return null
      return 'en';
    }
    if (count($locale) == 1) {
      return $code;
    }
    if (count($locale) == 2) {
      return $locale[0];
    }
  }
}


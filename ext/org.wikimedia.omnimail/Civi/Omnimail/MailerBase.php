<?php

namespace Civi\Omnimail;

/**
 * Shared functionality for mailer classes
 */
abstract class MailerBase {

  /**
   * RTL languages, comes from
   * http://en.wikipedia.org/wiki/Right-to-left#RTL_Wikipedia_languages TODO:
   * move to the LanguageTag module once that's available from here.
   */
  static public $rtlLanguages = [
    'ar',
    'arc',
    'bcc',
    'bqi',
    'ckb',
    'dv',
    'fa',
    'glk',
    'he',
    'ku',
    'mzn',
    'pnb',
    'ps',
    'sd',
    'syc',
    'ug',
    'ur',
    'yi',
  ];

  /**
   * Wrap raw HTML in a full document
   *
   * This is necessary to convince recalcitrant mail clients that we are
   * serious about the character encoding.
   *
   * @param string $html
   *
   * @return string
   */
  protected function wrapHtmlSnippet($html, $locale = NULL): string {
    if (preg_match('/<html.*>/i', $html)) {
      return $html;
    }

    $langClause = '';
    $bodyStyle = '';
    if ($locale) {
      $langClause = "lang=\"{$locale}\"";

      $localeComponents = explode('-', $locale);
      $bareLanguage = $localeComponents[0];
      if (in_array($bareLanguage, self::$rtlLanguages)) {
        $bodyStyle = 'style="text-align:right; direction:rtl;"';
      }
    }

    return "
<html {$langClause}>
<head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
</head>
<body {$bodyStyle}>
{$html}
</body>
</html>";
  }

  /**
   * Split a string list of addresses, separated by commas or whitespace, into
   * an array.
   *
   * @param string $to
   *
   * @deprecated
   *
   * @return array
   */
  protected function splitAddresses($to) {
    return preg_split('/\\s*[,\\n]\\s*/', $to, -1, PREG_SPLIT_NO_EMPTY);
  }

}

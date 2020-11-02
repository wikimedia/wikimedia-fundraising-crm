<?php

namespace Civi\Omnimail;

use Html2Text\Html2Text;

/**
 * Shared functionality for mailer classes
 */
abstract class MailerBase {
  /**
   * RTL languages, comes from http://en.wikipedia.org/wiki/Right-to-left#RTL_Wikipedia_languages
   * TODO: move to the LanguageTag module once that's available from here.
   */
  static public $rtlLanguages = array(
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
  );

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
  protected function wrapHtmlSnippet( $html, $locale = null ) {
    if ( preg_match( '/<html.*>/i', $html ) ) {
      watchdog( 'wmf_communication',
        "Tried to wrap something that already contains a full HTML document.",
        NULL, WATCHDOG_ERROR );
      return $html;
    }

    $langClause = '';
    $bodyStyle = '';
    if ( $locale ) {
      $langClause = "lang=\"{$locale}\"";

      $localeComponents = explode( '-', $locale );
      $bareLanguage = $localeComponents[0];
      if ( in_array( $bareLanguage, self::$rtlLanguages ) ) {
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

  protected function normalizeContent( &$email ) {
    $converter = new Html2Text( $email['html'], false, array( 'do_links' => 'table' ) );
    $email['plaintext'] = wordwrap( $converter->get_text(), 100 );

    if ( $email['plaintext'] === false ) {
      watchdog( 'thank_you', "Text rendering of template failed in {$email['locale']}.", array(), WATCHDOG_ERROR );
      throw new \WmfException( \WmfException::UNKNOWN, "Could not render plaintext" );
    }
  }

  /**
   * Split a string list of addresses, separated by commas or whitespace, into an array.
   * @param string $to
   * @return array
   */
  protected function splitAddresses( $to ) {
    return preg_split( '/\\s*[,\\n]\\s*/', $to, -1, PREG_SPLIT_NO_EMPTY );
  }
}

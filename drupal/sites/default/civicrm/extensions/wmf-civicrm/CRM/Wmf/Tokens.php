<?php

use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;

/**
 * Class CRM_Wmf_Tokens
 */
class CRM_Wmf_Tokens {

  /**
   * WMF token parser.
   *
   * This parses wmf specific tokens.
   *
   * @param \Civi\Token\Event\TokenValueEvent $e
   */
  public static function onEvalTokens(TokenValueEvent $e): void {
    foreach ($e->getRows() as $row) {
      $tokens = $e->getTokenProcessor()->getMessageTokens()['wmf_url'] ?? [];
      foreach ($tokens as $token) {
        $row->tokens('wmf_url', $token, self::getUrl($token, $row->context['contact']['email'], $row->context['language']));
      }
    }
  }

  /**
   * @param string $type
   * @param string $language
   *
   * @return string
   */
  protected static function getUrl($type, $email, $language) {
    switch ($type) {
      case 'new_recur' :
        return 'https://donate.wikimedia.org/wiki/Ways_to_Give/'
          . substr($language, 0, 2)
          . '?rdfrom=%2F%2Ffoundation.wikimedia.org%2Fw%2Findex.php%3Ftitle%3DWays_to_Give%2Fen%26redirect%3Dno#Monthly_gift&utm_medium=civi-mail&utm_campaign=FailedRecur&utm_source=FY2021_FailedRecur';

      case 'unsubscribe' :
        return build_unsub_link(-1, $email, substr($language, 0, 2));

    }
    return '';
  }

  /**
   * Declare tokens.
   *
   * @param \Civi\Token\Event\TokenRegisterEvent $e
   */
  public static function onListTokens(TokenRegisterEvent $e): void {
    $e->entity('wmf_url')
      ->register('unsubscribe', ts('Unsubscribe url'))
      ->register('new_recur', ts('New recurring url'));
  }

}

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
      $tokens = $e->getTokenProcessor()->getMessageTokens();
      foreach (($tokens['wmf_url'] ?? []) as $token) {
        $row->tokens('wmf_url', $token, self::getUrl($token, $row->context['contact']['email'] ?? '', $row->context['locale']));
      }
      if (isset($tokens['now'])) {
        // CiviCRM doesn't do full locale date handling. It relies on .pot files
        // and just translates the words. We add our own 'now.MMMM' token for the now-date.
        $dateFormatter = new \IntlDateFormatter($row->context['locale'], NULL, NULL);
        foreach ($tokens['now'] as $token) {
          $dateFormatter->setPattern($token);
          $row->tokens('now', $token, $dateFormatter->format(new \DateTime()));
        }
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
          . '?rdfrom=%2F%2Ffoundation.wikimedia.org%2Fw%2Findex.php%3Ftitle%3DWays_to_Give%2Fen%26redirect%3Dno&utm_medium=civi-mail&utm_campaign=FailedRecur&utm_source=FY2021_FailedRecur';

      case 'new_recur_brief' :
        return 'https://donate.wikimedia.org/wiki/Ways_to_Give/'
          . substr($language, 0, 2) . '#monthly';

      case 'unsubscribe' :
        return build_unsub_link(-1, $email, substr($language, 0, 2));

      case 'cancel' :
        return 'https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language='
          . substr($language, 0, 2);

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
      ->register('new_recur', ts('New recurring url'))
      ->register('cancel', ts('Cancel recurring url'))
      ->register('new_recur_brief', ts('New recurring url with less creepy stuff'))
    ;
    $e->entity('now')
      ->register('MMMM', ts('Current month'));
  }

}

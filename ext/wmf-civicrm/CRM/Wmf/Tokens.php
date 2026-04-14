<?php

use Civi\Api4\WMFLink;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;
use Civi\WMFHook\PreferencesLink;

/**
 * Class CRM_Wmf_Tokens
 */
class CRM_Wmf_Tokens {

  /**
   * WMF token parser.
   *
   * This parses wmf specific tokens.
   * wmf_url tokens and the now token are used only in system message templates.
   * action tokens are used in mailings.
   *
   * @param \Civi\Token\Event\TokenValueEvent $e
   */
  public static function onEvalTokens(TokenValueEvent $e): void {
    foreach ($e->getRows() as $row) {
      $tokens = $e->getTokenProcessor()->getMessageTokens();
      if (empty($tokens['wmf_url']) && empty($tokens['action']) && empty($tokens['now'])) {
        continue;
      }
      $contactID = $row->tokenProcessor->rowContexts[$row->tokenRow]['contact']['id']
        ?? $row->tokenProcessor->rowContexts[$row->tokenRow]['contactId']
        ?? NULL;
      $email = $row->tokenProcessor->rowContexts[$row->tokenRow]['contact']['email_primary.email'] ?? '';
      $locale = $row->tokenProcessor->context['locale'] ?? NULL;
      foreach (($tokens['wmf_url'] ?? []) as $token) {
        $row->tokens('wmf_url', $token, self::getUrl($token, $email, $locale, $row, $contactID));
      }
      if (array_key_exists('action', $tokens)) {
        // Now override the core action urls. We need to override both html & plain text versions.
        // email and locale are not filled (and not used) in this case.
        if (in_array('optOutUrl', $tokens['action'])) {
          $row->format('text/html')->tokens('action', 'optOutUrl', htmlentities(self::getUrl('optOutUrl', $email, $locale, $row, $contactID)));
          $row->format('text/plain')->tokens('action', 'optOutUrl', self::getUrl('optOutUrl', $email, $locale, $row, $contactID));
        }
        if (in_array('unsubscribeUrl', $tokens['action'])) {
          $row->format('text/html')->tokens('action', 'unsubscribeUrl', htmlentities(self::getUrl('unsubscribeUrl', $email, $locale, $row, $contactID)));
          $row->format('text/plain')->tokens('action', 'unsubscribeUrl', self::getUrl('unsubscribeUrl', $email, $locale, $row, $contactID));
        }
      }
      // This token could probably be replaced by {domain.now|crmDate:MMMM} or
      // similar. It is used in our recurring failure email to render the month
      // their contribution failed in.
      if (isset($tokens['now'])) {
        // CiviCRM doesn't do full locale date handling. It relies on .pot files
        // and just translates the words. We add our own 'now.MMMM' token for the now-date.
        $dateFormatter = new \IntlDateFormatter($locale);
        foreach ($tokens['now'] as $token) {
          $dateFormatter->setPattern($token);
          $row->tokens('now', $token, $dateFormatter->format(new \DateTime()));
        }
      }
    }
  }

  /**
   * @param string $type
   * @param string $email
   * @param string $language
   * @param \Civi\Token\TokenRow $row
   * @param int|null $contactID
   * @return string
   * @throws CRM_Core_Exception
   */
  protected static function getUrl($type, $email, $language, $row, ?int $contactID = NULL) {
    $shortLang = substr($language ?? '', 0, 2);
    switch ($type) {
      case 'new_recur':
        return self::getNewRecurUrl($shortLang, $row, $contactID);

      case 'unsubscribe':
        return WMFLink::getUnsubscribeURL(FALSE)
          ->setEmail($email)
          ->setContactID($contactID)
          ->setLanguage($language)
          ->execute()->first()['unsubscribe_url'];

      case 'cancel':
        return 'https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language='
          . $shortLang;

      case 'optOutUrl':
      case 'unsubscribeUrl':
        return $contactID ? PreferencesLink::getPreferenceUrl($contactID) : '';

      case 'donorPortalUrl':
        return $contactID ? PreferencesLink::getDonorPortalUrl($contactID) : '';
    }
    return '';
  }

  /**
   * @param string $shortLang
   * @param \Civi\Token\TokenRow $row
   * @param int|null $contactID
   * @return string
   */
  protected static function getNewRecurUrl(string $shortLang, $row, ?int $contactID): string {
    $recurID = $row->tokenProcessor->context['contribution_recurId'] ?? NULL;
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('amount', 'contribution_recur_smashpig.original_country:abbr', 'frequency_unit', 'contact_id')
      ->addWhere('id', '=', $recurID)
      ->execute()->first();
    $frequency = match($recur['frequency_unit'] ?? NULL) {
      'year' => 'annual',
      'month' => 'monthly',
      default => NULL,
    };
    $contactID = $contactID ?? $recur['contact_id'] ?? NULL;
    $emailCount = match($row->tokenProcessor->context['workflow'] ?? NULL) {
      'recurring_failed_message' => '1',
      'recurring_second_failed_message' => '2',
      default => '',
    };
    $params = [
      'wmf_campaign' => 'FailedRecur',
      'wmf_medium' => 'civi-mail',
      'appeal' => 'SupportingWikipedia',
      'monthlypitch' => '1',
      'wmf_source' => 'FailedRecur' . $emailCount,
      'uselang' => $shortLang,
      'contact_id' => $contactID,
      'frequency' => $frequency,
      'preSelect' => $recur['amount'] ?? NULL,
      'country' => $recur['contribution_recur_smashpig.original_country:abbr'] ?? NULL,
    ];
    if ($contactID) {
      $params['checksum'] = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
    }
    return 'https://donate.wikimedia.org/?' . http_build_query(array_filter($params));
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
      ->register('donorPortalUrl', ts('Donor portal url, if eligible'));
    $e->entity('now')
      ->register('MMMM', ts('Current month'));
  }

}

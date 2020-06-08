<?php

/**
 * Class CRM_CiviDataTranslate_Tokens
 */
class CRM_CiviDataTranslate_Tokens {

  /**
   * This is a very basic token parser.
   *
   * It relies on the calling function to retrieve variables, leaving the permission checking to the
   * calling function. At this stage it simply parses them in, accepting one entity.
   *
   * More useful extension is handling of formatting for the various values.
   *
   * @param \Civi\Token\Event\TokenValueEvent $e
   */
  public static function onEvalTokens(\Civi\Token\Event\TokenValueEvent $e) {
    foreach ($e->getRows() as $row) {
      $tokens = $e->getTokenProcessor()->getMessageTokens();
      $entity = $row->context['entity'];
      // Use lcfirst - ie contributionRecur
      $tokenEntity = lcfirst($entity);
      if (!$entity || empty($tokens) || !isset($tokens[$tokenEntity])) {
        continue;
      }
      foreach ($tokens[$tokenEntity] as $token) {
        if (isset($row->context[$token])) {
          /** @var Civi\Token\TokenRow $row */
          $row->tokens($tokenEntity, $token, $row->context[$token]);
        }
        elseif (isset($row->context[str_replace('__format_money', '', $token)])) {
          /** @var Civi\Token\TokenRow $row */
          // @todo - calling our Twig class is quick & dirty but it's not a good fix in that it's too entangled
          // Ref https://lab.civicrm.org/dev/translation/-/issues/48 for getting a library into civi
          $value = $row->context[str_replace('__format_money', '', $token)];
          $string = TwigLocalization::l10n_currency($row->context['currency'] . ' ' . $value, $row->context['language']);
          $row->tokens($tokenEntity, $token, $string);
        }
      }
      if (isset($tokens['now'])) {
        // e.g {now.MMMM} - this formats now in a localised way using format http://userguide.icu-project.org/formatparse/datetime
        // @todo consider if that is the right sort of format to use - elsewhere in Civi we use strtotime
        // formats. Also note that ideally we would add these to the front-end user's experience & give them
        // labels so it would matter less.
        $dateFormatter = new \IntlDateFormatter($row->context['language'], NULL, NULL);
        foreach ($tokens['now'] as $token) {
          $dateFormatter->setPattern($token);
          $row->tokens('now', $token, $dateFormatter->format(new \DateTime()));
        }
      }
    }
  }

  /**
   * Declare tokens.
   *
   * At this stage this is mostly a sample / stub. It would be served up to a UI but that
   * is mostly absent as yet.
   *
   * @param \Civi\Token\Event\TokenRegisterEvent $e
   */
  public static function onListTokens(\Civi\Token\Event\TokenRegisterEvent $e) {
    $e->entity('contributionRecur')
      ->register('amount', ts('Recurring amount'));
  }
}

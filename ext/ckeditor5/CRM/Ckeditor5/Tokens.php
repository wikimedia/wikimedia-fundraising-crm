<?php

/**
 * Build context-aware token catalogues for the CKEditor token picker.
 */
class CRM_Ckeditor5_Tokens {

  /**
   * Return the core entity schema used to discover available tokens.
   *
   * @return string[]
   */
  public static function defaultSchema() {
    return [
      'contactId',
      'mailingId',
      'contributionId',
      'participantId',
      'membershipId',
      'eventId',
      'activityId',
      'caseId',
      'grantId',
      'pledgeId',
      'groupId',
    ];
  }

  /**
   * Return tokens in the grouped format used by CiviCRM select widgets.
   *
   * @param string $preset
   * @return array<int, array<string, mixed>>
   */
  public static function getForPreset($preset) {
    $schema = self::defaultSchema();
    $event = \Civi\Core\Event\GenericHookEvent::create([
      'preset' => $preset,
      'schema' => &$schema,
    ]);
    Civi::dispatcher()->dispatch('civi.ckeditor5.tokenSchema', $event);
    $schema = array_values(array_unique(array_filter($schema, function($field) {
      return is_string($field) && $field !== '';
    })));
    $processor = new \Civi\Token\TokenProcessor(Civi::dispatcher(), [
      'schema' => $schema,
    ]);
    return CRM_Utils_Token::formatTokensForDisplay(self::normalizeLabels($processor->listTokens()));
  }

  /**
   * Preserve third-party tokens even when their provider omits a label.
   *
   * @param array<string, mixed> $tokens
   * @return array<string, string>
   */
  public static function normalizeLabels(array $tokens) {
    foreach ($tokens as $token => &$label) {
      if (!is_string($label) || trim($label) === '') {
        $label = $token;
      }
    }
    unset($label);
    return $tokens;
  }

}

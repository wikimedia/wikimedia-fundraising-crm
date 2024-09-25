<?php
// Class to hold wmf helper functions.

namespace Civi\WMFHelper;

use Civi\Api4\Contact;
use Civi\Api4\OptionValue;

class Language {

  /**
   * Get the best language to use, based on the language information we have.
   *
   * @throws \CRM_Core_Exception
   */
  public static function getLanguageCode(string $suppliedLanguage): string {
    $validLanguages = self::getValidLanguages();

    if (isset($validLanguages[$suppliedLanguage])
      && $validLanguages[$suppliedLanguage] !== $suppliedLanguage) {
      // if the language matches a valid language (ie one with a real
      // description return it - eg. fr_CA will return here)
      return $suppliedLanguage;
    }
    $variants = self::getDefaultVariantForLanguage();
    $baseLanguage = substr($suppliedLanguage, 0, 2);
    if (isset($variants[$baseLanguage])) {
      // e.g 'fr' will return 'fr_FR' here.
      return $variants[$baseLanguage];
    }
    foreach (array_keys($validLanguages) as $validLanguage) {
      // Go through the remaining languages & return the first match
      // e.g en_NO will return the first valid en language it hits here.
      // this helps with our bad data....
      if (strpos($validLanguage, $baseLanguage) === 0) {
        return $validLanguage;
      }
    }
    // If the language is present but inactive then enable it - this
    // really just affects dev sites. Everything under the sun, including around 2000
    // made-up languages are enabled on live but dev sites might be missing Latvian.
    $optionValue = OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'languages')
      ->addWhere('value', '=', $suppliedLanguage)
      ->addWhere('is_active', '=', FALSE)->addSelect('id')->execute()->first();
    if (!empty($optionValue)) {
      OptionValue::update(FALSE)
        ->addWhere('id', '=', $optionValue['id'])
        ->setValues(['is_active' => TRUE])
        ->execute();
      \Civi::cache('metadata')->clear();
      return $suppliedLanguage;
    }
    throw new \CRM_Core_Exception($suppliedLanguage . ' not available');
  }

  /**
   * If we only know the 'meta' language then use the default variant.
   *
   * For example if we know it is 'German' but not what flavour of German
   * it is better to map that to 'German German' which is meaninful
   * in civiland that to leave it as 'meh some sort of German'.
   *
   * The 'meh' approach either winds up with us using German anyway or
   * slipping back to English (which is worse).
   *
   * I ran the query to find this on the 'stock' civi list.
   * SELECT value, count(*) c FROM civicrm_option_value WHERE option_group_id = 86
   * GROUP BY value
   * HAVING c > 1
   *
   * | value | c | group\_concat\(name\) | group\_concat\(label\) |
   * | :--- | :--- | :--- | :--- |
   * | de | 2 | de\_DE,de\_CH | German,German \(Swiss\) |
   * | en | 4 | en\_AU,en\_CA,en\_GB,en\_US | English \(Australia\),English \(Canada\),English \(United Kingdom\),English \(United States\) |
   * | es | 2 | es\_ES,es\_PR | Spanish; Castilian \(Spain\),Spanish; Castilian \(Puerto Rico\) |
   * | fr | 2 | fr\_CA,fr\_FR | French \(Canada\),French \(France\) |
   * | nl | 2 | nl\_NL,nl\_BE | Dutch \(Netherlands\),Dutch \(Belgium\) |
   * | pt | 2 | pt\_BR,pt\_PT | Portuguese \(Brazil\),Portuguese \(Portugal\) |
   * | zh | 2 | zh\_CN,zh\_TW | Chinese \(China\),Chinese \(Taiwan\) |
   */
  protected static function getDefaultVariantForLanguage(): array {
    // This is actually duplicated in Message.Load api.
    // It's so trivial + the comment block makes sense here but not there,
    // that I think the duplication is OK....
    return [
      'de' => 'de_DE',
      'en' => 'en_US',
      'fr' => 'fr_FR',
      'es' => 'es_ES',
      'nl' => 'nl_NL',
      'pt' => 'pt_PT',
      'zh' => 'zh_TW',
    ];
  }

  /**
   * Get the valid languages.
   *
   * These are the languages we are using that don't look made up.
   *
   * @return array
   *
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  protected static function getValidLanguages(): array {
    if (!\Civi::cache('metadata')->has('wmf_valid_languages')) {
      $validLanguages = Contact::getFields(FALSE)
        ->setLoadOptions(TRUE)
        ->addWhere('name', '=', 'preferred_language')
        ->execute()->first()['options'];
      foreach ($validLanguages as $languageCode => $language) {
        if ($languageCode === $language) {
          unset($validLanguages[$language]);
        }
      }
      \Civi::cache('metadata')->set('wmf_valid_languages', $validLanguages);
    }
    return \Civi::cache('metadata')->get('wmf_valid_languages');
  }

}

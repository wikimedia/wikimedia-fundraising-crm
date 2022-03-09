<?php

use Civi\WMFHelpers\Language;

function _wmf_civicrm_update_8236_update_preferred_language() {
  civicrm_initialize();
  $result = CRM_Core_DAO::executeQuery('SELECT
    c.id, c.preferred_language
FROM
    civicrm_contact c
        LEFT JOIN
    civicrm_option_value ov ON c.preferred_language = ov.name
        AND option_group_id = 86
WHERE
    ov.label IS NULL
    AND c.preferred_language IS NOT NULL');
  while ($result->fetch()) {
    wmf_civicrm_set_default_for_invalid_locale_with_supported_lang($result->id, $result->preferred_language);
  }
}

/**
 * Checks the language in the invalid locale string is supported on civi
 * then updates the contact's preferred language with the default locale for that language
 * Skips locale string with unsupported language
 *
 * @param $contact_id
 * @param $preferred_language
 * @throws API_Exception
 */
function wmf_civicrm_set_default_for_invalid_locale_with_supported_lang ($contact_id, $preferred_language) {
  $locale = strtolower($preferred_language);
  $parts = explode("_", $locale);
  $fallback_locales = array(
    "ab" => "ab_GE",
    "ak" => "ak_GH",
    "bar" => "de_DE",
    "als" => "sq_AL",
    "ang" => "en_GB",
    "ast" => "es_ES",
    "ba" => "ru_RU",
    "bh" => "bh_IN",
    "bi" => "bi_VU",
    "bo" => "bo_CN",
    "ch" => "ch_GU",
    "co" => "co_FR",
    "cv" => "cv_RU",
    "fj" => "fj_FJ",
    "fo" => "fo_FO",
    "fur" => "it_IT",
    "fy" => "fy_NL",
    "gb" => "en_GB",
    "gb_gb" => "en_GB",
    "gb_nl" => "en_GB",
    "gb_us" => "en_US",
    "ger" => "de_DE",
    "gu" => "gu_IN",
    "gu_US" => "gu_IN",
    "gv" => "gv_IM",
    'hsb' => "de_DE",
    "ht" => "fr_FR",
    "hu_co" => "hu_HU",
    "hu_mt" => "hu_HU",
    "is" => "is_IS",
    "is_ca" => "is_IS",
    "is_nl" => "is_IS",
    "is_no" => "is_IS",
    "is_se" => "is_IS",
    "jbo" => "en_US",
    "jv" => "jv_ID",
    "kk" => "kk_KZ",
    "kn" => "kn_IN",
    "ku" => "ku_IQ",
    "kw" => "kw_GB",
    "lzh" => "zh_TW",
    "mi" => "mi_NZ",
    "mr" => "mr_IN",
    "ms" => "ms_MY",
    "ms_ca" => "ms_MY",
    "ms_gb" => "ms_MY",
    "ms_id" => "ms_MY",
    "ms_ie" => "ms_MY",
    "ms_in" => "ms_MY",
    "ms_nz" => "ms_MY",
    "ms_sg" => "ms_MY",
    "nan" => "zh_TW",
    "nds" => "de_DE",
    "nds_nl" => "nl_NL",
    "nrm" => "fr_FR",
    "or" => "or_IN",
    "pa" => "pa_IN",
    "pms" => "it_IT",
    "pnb" => "pa_IN",
    "rw" => "rw_RW",
    "sah" => "ru_RU",
    "sc" => "sc_IT",
    "sc_cn" => "sc_IT",
    "scn" => "it_IT",
    "sco" => "en_GB",
    "sd" => "sd_IN",
    "sh" => "bs_BA",
    "sh_ba" => "bs_BA",
    "sh_de" => "bs_BA",
    "sh_hr" => "bs_BA",
    "sh_me" => "bs_BA",
    "sh_rs" => "bs_BA",
    "sh_us" => "bs_BA",
    "sl" => "sl_SI",
    "sl_at" => "sl_SI",
    "sl_au" => "sl_SI",
    "sl_ba" => "sl_SI",
    "sl_ca" => "sl_SI",
    "sl_de" => "sl_SI",
    "sl_lu" => "sl_SI",
    "sl_nl" => "sl_SI",
    "sl_no" => "sl_SI",
    "sl_ru" => "sl_SI",
    "sl_se" => "sl_SI",
    "sl_sk" => "sl_SI",
    "sl_us" => "sl_SI",
    "su" => "su_ID",
    "ta" => "ta_IN",
    "ta_au" => "ta_IN",
    "ta_fi" => "ta_IN",
    "ta_lk" => "ta_IN",
    "ta_my" => "ta_IN",
    "ta_no" => "ta_IN",
    "ta_nz" => "ta_IN",
    "ta_sa" => "ta_IN",
    "ta_tw" => "ta_IN",
    "ti" => "ti_ET",
    "vec" => "it_IT",
    "vls" => "nl_NL",
    "wuu" => "zh_CN",
    "yue" => "yue_CN"
  );
  try {
    if ( array_key_exists( $locale, $fallback_locales ) ) {
      $default_locale = $fallback_locales[$locale];
    } else {
      $default_locale = Language::getLanguageCode($parts[0]);
    }
    CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET preferred_language=%1  WHERE id = %2', array(
      1 => array(
        $default_locale,
        'String'
      ),
      2 => array(
        $contact_id,
        'String'
      )
    ));
  } catch (\CRM_Core_Exception $e) {
    watchdog('update_8236',
      'Error: wmf_civicrm_set_default_for_invalid_locale_with_supported_lang on %preferred_language execution failed.
       Cause: %message',
      array(
        '%preferred_language' => $preferred_language,
        '%message' => $e->getMessage()
      ) );
  }
}

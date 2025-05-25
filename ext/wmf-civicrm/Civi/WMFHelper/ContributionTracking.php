<?php

namespace Civi\WMFHelper;

class ContributionTracking {

  /**
   * Get the device type id from the url parameter utm_source.
   *
   * We are using a hard-coded string search with hard coded values as these are
   * based on convention.
   *
   * These values are stored as an option group (device_type) in CiviCRM.
   *
   * @param string $utmSource
   *
   * @return int|null
   */
  public static function getDeviceTypeID(string $utmSource): ?int {
    if (strpos($utmSource, '_dsk_') !== FALSE) {
      return 1;
    }
    if (strpos($utmSource, '_m_') !== FALSE) {
      return 2;
    }
    return NULL;
  }

  /**
   * Get the banner type id from the url parameter utm_source.
   *
   * We are using a hard-coded string search with hard coded values as these are
   * based on convention.
   *
   * These values are stored as an option group (banner_type) in CiviCRM.
   *
   * @param string $utmSource
   *
   * @return int
   */
  public static function getBannerTypeID(string $utmSource): int {
    if (strpos($utmSource, '_lg_') !== FALSE) {
      return 1;
    }
    if (strpos($utmSource, '_sm_') !== FALSE) {
      return 2;
    }
    return 3;
  }

  /**
   * Get the banner type id from the url parameter utm_source.
   *
   * We are using a hard-coded string search with hard coded values as these are
   * based on convention.
   *
   * These values should match Acoustic identifiers.
   *
   * @param string $utmSource
   *
   * @return string|null
   */
  public static function getMailingIdentifier(string $utmSource): ?string {
    if (empty($utmSource)) {
      return NULL;
    }
    if (strpos($utmSource, 'sp') === 0) {
      return substr($utmSource, 0, 10);
    }
    if (strpos($utmSource, '70761231.d') === 0) {
      return 'sp70761231';
    }
    return NULL;
  }


  /**
   * Get payment methods.
   *
   * We add static caching here - but actually we are already calling a cached function
   * so any benefit is marginal.
   *
   * @return array
   */
  protected static function getPaymentMethods(): array {
    if (!isset(\Civi::$statics[__CLASS__]['payment_methods'])) {
      \Civi::$statics[__CLASS__]['payment_methods'] = array_flip(\CRM_Wmf_BAO_ContributionTracking::buildOptions('payment_method_id', 'validate'));
    }
    return \Civi::$statics[__CLASS__]['payment_methods'];
  }

  /**
   * Get the parameters to store in civicrm_contribution_tracking from the raw tracking data.
   *
   * @param array $rawData
   *
   * @return array
   */
  public static function getContributionTrackingParameters(array $rawData): array {
    $contributionTracking = ['id' => $rawData['id']];

    $fieldsToCopy = [
      'amount',
      'appeal',
      'browser',
      'browser_version',
      'contribution_id',
      'country',
      'currency',
      'gateway',
      'is_recurring',
      'language',
      'os',
      'os_version',
      'payments_form_variant',
      'referrer',
      'utm_campaign',
      'utm_key',
      'utm_medium',
      'utm_source',
      'tracking_date',
    ];
    foreach ($fieldsToCopy as $field) {
      if (isset($rawData[$field])) {
        $contributionTracking[$field] = $rawData[$field];
      }
    }

    if (!empty($rawData['ts'])) {
      $contributionTracking['tracking_date']  = $rawData['ts'];
    }

    $paymentMethods = self::getPaymentMethods();
    if (
      !empty($rawData['payment_method']) &&
      !empty($paymentMethods[$rawData['payment_method']])
    ) {
      $contributionTracking['payment_method_id'] = $paymentMethods[$rawData['payment_method']];
    }
    if (!empty($rawData['payment_submethod'])) {
      $contributionTracking['payment_submethod_id'] = \CRM_Core_PseudoConstant::getKey(
        'CRM_Wmf_DAO_ContributionTracking',
        'payment_submethod_id',
        FinanceInstrument::getPaymentInstrument( $rawData )
      );
    }
    if (!empty($rawData['utm_source'])) {
      $contributionTracking['is_test_variant'] = (strpos($rawData['utm_source'] ?? '', '_cnt_') === FALSE) && (strpos($rawData['utm_source'] ?? '', '_cnt.') === FALSE);
      $sourceFields = explode('.', $rawData['utm_source']);
      // These are escaped so 'safe' but way too annoying to populate into multiple fields.
      // The sql ones *might* be legit without the spaces?
      $annoyingHackerStrings = [' union ', 'script', ' select '];
      foreach ($annoyingHackerStrings as $hackerString) {
        if (stripos($rawData['utm_source'], $hackerString) !== FALSE) {
          $sourceFields = [];
        }
      }
      $mailingIdentifier = self::getMailingIdentifier((string) ($rawData['utm_source'] ?? ''));
      if ($mailingIdentifier) {
        $contributionTracking['mailing_identifier'] = $mailingIdentifier;
      }
      else {
        $banner = $sourceFields[0] ?? NULL;
        $bannerParts = explode('_', $banner ?? '');
        if ($banner) {
          $contributionTracking['banner'] = $banner;
        }
        if ($banner && !empty($bannerParts[1])) {
          $contributionTracking['banner_variant'] = $bannerParts[1];
        }
        $deviceTypeID = self::getDeviceTypeID((string) $rawData['utm_source']);
        if ($deviceTypeID) {
          $contributionTracking['device_type_id'] = $deviceTypeID;
        }
        $bannerSizeID = self::getBannerTypeID((string) $rawData['utm_source']);
        if ($bannerSizeID) {
          $contributionTracking['banner_size_id'] = $bannerSizeID;
        }
      }
      if (!empty($sourceFields[1])) {
        $contributionTracking['landing_page'] = $sourceFields[1];
      }
    }

    if (!empty($rawData['utm_key'])) {
      $contributionTracking['is_pay_fee'] = (strpos($rawData['utm_key'], 'ptf_1') !== FALSE);
      if ($contributionTracking['is_recurring'] ?? false) {
        $contributionTracking['recurring_choice_id'] = (stripos($rawData['utm_key'], 'Upsell') !== FALSE || strpos($rawData['utm_key'], 'Upsell') !== FALSE) ? 1 : 2;
      }
    }

    if (
      !empty($rawData['banner_history_log_id']) &&
      preg_match('/^[0-9a-f]{10,20}$/', $rawData['banner_history_log_id'] )
    ) {
      $contributionTracking['banner_history_log_id'] = $rawData['banner_history_log_id'];
    }

    return $contributionTracking;
  }

}

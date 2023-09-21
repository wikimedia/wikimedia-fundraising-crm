<?php

namespace Civi\WMFHelpers;

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
      'contribution_id',
      'country',
      'currency',
      'gateway',
      'is_recurring',
      'language',
      'payments_form_variant',
      'referrer',
      'utm_campaign',
      'utm_key',
      'utm_medium',
      'utm_source',
    ];
    foreach ($fieldsToCopy as $field) {
      if (isset($rawData[$field])) {
        $contributionTracking[$field] = $rawData[$field];
      }
    }

    if (!empty($rawData['ts'])) {
      $contributionTracking['tracking_date']  = $rawData['ts'];
    }

    // TODO remove legacy form_amount and payments_form fields here once we start
    // sending currency, amount, gateway, appeal, and variant separately.
    if (!empty($rawData['form_amount'])) {
      $formAmount = explode(' ', $rawData['form_amount']);
      $contributionTracking['currency'] = $formAmount[0];
      $contributionTracking['amount'] = is_numeric($formAmount[1] ?? NULL) ? $formAmount[1] : NULL;
    }

    if (!empty($rawData['payments_form'])) {
      $paymentsFormFields = explode('.', $rawData['payments_form'] ?? '');
      $contributionTracking['gateway'] = $paymentsFormFields[0];
      if (empty($contributionTracking['appeal'])) {
        $contributionTracking['appeal'] = empty( $paymentsFormFields[1] ) ? NULL : mb_substr( $paymentsFormFields[1], 0, 64 );
      }
      if (empty($contributionTracking['payments_form_variant'])) {
        $contributionTracking['payments_form_variant'] = (!empty($paymentsFormFields[1]) && stripos($paymentsFormFields[1], 'v=') !== FALSE) ? substr($paymentsFormFields[1], 3) : NULL;
      }
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

      // TODO: stop pulling these out of utm_source once we are sending them all by themselves.
      if (!empty($sourceFields[2]) && empty($contributionTracking['payment_method_id'])) {
        if (!empty($paymentMethods[$sourceFields[2]])) {
          $contributionTracking['payment_method_id'] = $paymentMethods[$sourceFields[2]];
        }
        elseif (strpos($sourceFields[2], 'r') === 0 && !empty($paymentMethods[substr($sourceFields[2], 1)])) {
          $contributionTracking['payment_method_id'] = $paymentMethods[substr($sourceFields[2], 1)];
          $contributionTracking['is_recurring'] = 1;
        }
        if (!empty($sourceFields[3])) {
          // getKey returns NULL if NULL - but since submethod being present is the exception the empty check is
          // a minor optimisation.
          $contributionTracking['payment_submethod_id'] = \CRM_Core_PseudoConstant::getKey('CRM_Wmf_DAO_ContributionTracking', 'payment_submethod_id', $sourceFields[3]);
        }
      }
    }

    if (!empty($rawData['utm_key'])) {
      $contributionTracking['is_pay_fee'] = (strpos($rawData['utm_key'], 'ptf_1') !== FALSE);
      if ($contributionTracking['is_recurring'] ?? false) {
        $contributionTracking['recurring_choice_id'] = (stripos($rawData['utm_key'], 'Upsell') !== FALSE || strpos($rawData['utm_key'], 'Upsell') !== FALSE) ? 1 : 2;
      }
    }

    return $contributionTracking;
  }

}

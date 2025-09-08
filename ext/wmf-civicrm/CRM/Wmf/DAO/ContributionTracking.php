<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $contribution_id
 * @property string $amount
 * @property string $currency
 * @property string $usd_amount
 * @property bool|string $is_recurring
 * @property string $referrer
 * @property string $utm_medium
 * @property string $utm_campaign
 * @property string $utm_key
 * @property string $gateway
 * @property string $appeal
 * @property string $payments_form_variant
 * @property string $banner
 * @property string $landing_page
 * @property int|string $payment_method_id
 * @property int|string $payment_submethod_id
 * @property string $language
 * @property string $country
 * @property string $tracking_date
 * @property string $os
 * @property string $os_version
 * @property string $browser
 * @property string $browser_version
 * @property int|string $recurring_choice_id
 * @property int|string $device_type_id
 * @property int|string $banner_size_id
 * @property bool|string $is_test_variant
 * @property string $banner_variant
 * @property bool|string $is_pay_fee
 * @property string $mailing_identifier
 * @property string $utm_source
 * @property string $banner_history_log_id
 */
class CRM_Wmf_DAO_ContributionTracking extends CRM_Core_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_contribution_tracking';

}

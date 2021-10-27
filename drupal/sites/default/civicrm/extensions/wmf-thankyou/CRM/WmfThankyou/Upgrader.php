<?php
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_WmfThankyou_Upgrader extends CRM_WmfThankyou_Upgrader_Base {

  /**
   * Add the relevant activity type.
   */
  public function install() {
    CRM_Core_BAO_OptionValue::ensureOptionValueExists([
      'label' => 'Sent year-end summary receipt',
      'name' => 'wmf_eoy_receipt_sent',
      'weight' => '1',
      'description' => 'Sent an email receipt summarizing all donations in a given year',
      'option_group_id' => 'activity_type',
    ]);
  }

}

<?php

use Civi\Api4\CustomField;

/**
 * @group WmfCampaigns
 */
class WmfCampaignTest extends BaseWmfDrupalPhpUnitTestCase {

  public $campaign_custom_field_name;

  public $campaign_key;

  public $notification_email;

  public $contact_id;

  public $option_value_id;

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
    unset(Civi::$statics['wmf_campaigns']['campaigns']);
    $this->campaign_custom_field_name = 'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID('Appeal');

    $this->campaign_key = 'fooCamp' . mt_rand();
    $this->notification_email = 'notifee@localhost.net';

    $contactID = $this->createTestContact([
      'contact_type' => 'Individual',
      'first_name' => 'Testes',
    ]);
    $this->contact_id = $contactID;

    db_merge('wmf_campaigns_campaign')
      ->key(['campaign_key' => $this->campaign_key])
      ->fields([
        'campaign_key' => $this->campaign_key,
        'notification_email' => $this->notification_email,
      ])
      ->execute();

    $result = civicrm_api3('OptionValue', 'create', [
      'option_group_id' => $this->wmf_civicrm_get_direct_mail_field_option_id(),
      'label' => $this->campaign_key,
      'value' => $this->campaign_key,
    ]);
    $this->option_value_id = $result['id'];
  }

  public function tearDown(): void {
    civicrm_api3('OptionValue', 'delete', [
      'option_group_id' => $this->wmf_civicrm_get_direct_mail_field_option_id(),
      'id' => $this->option_value_id,
    ]);
    db_delete('wmf_campaigns_campaign')
      ->condition('campaign_key', $this->campaign_key)
      ->execute();
    parent::tearDown();
  }


  /**
   * Get the name value for the option group for direct mail.
   *
   * Note that this function should become obsolete - I wanted to use
   * the code below in the calling function instead but it didn't work and
   * I want to check with Coleman first.
   *
   * Contribution::getFields(FALSE)
   * ->setLoadOptions(TRUE)
   * ->addWhere('field_name', '=', 'Gift_Data:Campaign')
   * ->execute();
   *}
   *
   * @throws \CRM_Core_Exception
   */
  private function wmf_civicrm_get_direct_mail_field_option_id() {
    if (!isset(\Civi::$statics['wmf-civicrm']['appeal_field_id'])) {
      \Civi::$statics['wmf-civicrm']['appeal_field_id'] = CustomField::get(FALSE)
        ->addWhere('name', '=', 'Appeal')
        ->setSelect(['option_group_id'])
        ->execute()->first()['option_group_id'];
    }
    return \Civi::$statics['wmf-civicrm']['appeal_field_id'];
  }

  function testMatchingDonation() {
    $result = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'contribution_type' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => '1.23',
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      $this->campaign_custom_field_name => $this->campaign_key,
    ]);
    $this->ids['Contribution'][$result['id']] = $result['id'];

    $this->assertEquals(1, $this->getMailingCount(),
      'Exactly one email was sent.');

    $mailing = $this->getMailing(0);
    $this->assertNotEquals(FALSE, strpos($mailing['html'], $this->campaign_key),
      'Campaign name found in notification email.');
  }

  /**
   * This is a bit silly - just tests core Civi behavior
   * that throws an exception when setting a custom field
   * to a value outside of the expected range.
   */
  public function testNonMatchingDonation(): void {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches("/fooCamp.*NOT/");
    $result = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'contribution_type' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => '1.23',
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
      $this->campaign_custom_field_name => $this->campaign_key . "NOT",
    ]);
    $this->ids['Contribution'][$result['id']] = $result['id'];

    $this->fail('Should have exceptioned out already.');
  }

  function testNoCampaignDonation() {
    $result = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contact_id,
      'contribution_type' => 'Cash',
      'currency' => 'USD',
      'payment_instrument' => 'Credit Card',
      'total_amount' => '1.23',
      'trxn_id' => 'TEST_GATEWAY ' . mt_rand(),
    ]);
    $this->ids['Contribution'][$result['id']] = $result['id'];

    $this->assertEquals(0, $this->getMailingCount());
  }
}

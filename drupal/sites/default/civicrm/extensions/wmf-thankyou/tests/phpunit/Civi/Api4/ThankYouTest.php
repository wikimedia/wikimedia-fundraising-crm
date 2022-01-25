<?php
namespace Civi\Api4;

use API_Exception;
use Civi;
use Civi\Test\Api3TestTrait;
use CRM_Core_DAO;
use CRM_Core_PseudoConstant;
use PHPUnit\Framework\TestCase;
use wmf_communication\TestMailer;

class ThankYouTest extends TestCase {

  use Api3TestTrait;

  protected $old_civimail;

  protected $old_civimail_rate;

  /**
   * IDS of various entities created.
   *
   * @var array
   */
  protected $ids = [];

  public function setUp(): void {
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
    parent::setUp();
    TestMailer::setup();
    $this->old_civimail = variable_get('thank_you_add_civimail_records', 'false');
    $this->old_civimail_rate = variable_get('thank_you_civimail_rate', 1.0);

    $contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'generousdonor@example.org',
      'city' => 'Somerville',
      'country' => 'US',
      'postal_code' => '02144',
      'state_province' => 'MA',
      'street_address' => '1 Davis Square',
      'first_name' => 'Test',
      'last_name' => 'Contact',
      'language' => 'en_US',
    ])['values']);
    $this->ids['Contact'] = [$contact['id']];
  }

  /**
   * Create a contribution, with some defaults.
   *
   * @param array $params
   * @param string|int $key
   *   Identifier to refer to contribution by.
   *
   * @return int
   */
  public function createContribution(array $params, $key): int {
    try {
      $this->ids['Contribution'][$key] = Contribution::create(FALSE)
        ->setValues(array_merge([
          'currency' => 'USD',
          'contact_id' => $this->ids['Contact'][0],
          'receive_date' => 'now',
          'payment_instrument_id:name' => 'Credit Card',
          'financial_type_id:name' => 'Donation',
          'total_amount' => 1.23,
          'contribution_extra.original_amount' => $params['total_amount'] ?? 1.23,
          'contribution_extra.original_currency' => 'USD',
          'contribution_extra.gateway' => 'thank_you_test_gateway',
          'contribution_extra.gateway_trxn_id' => 'thank_you_test_gateway 12345',
        ], $params))
        ->execute()
        ->first()['id'];
    }
    catch (API_Exception $e) {
      $this->fail($e->getMessage());
    }
    return $this->ids['Contribution'][$key];
  }

  /**
   * Post test cleanup.
   */
  public function tearDown(): void {
    try {
      Contribution::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contribution'])
        ->execute();
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)
        ->execute();
      variable_set('thank_you_add_civimail_records', $this->old_civimail);
      variable_get('thank_you_civimail_rate', $this->old_civimail_rate);
    }
    catch (API_Exception $e) {
      $this->fail($e->getMessage());
    }
    parent::tearDown();
  }

  /**
   * @throws \CiviCRM_API3_Exception
   */
  public function testGetEntityTagDetail(): void {
    unset (Civi::$statics['wmf_civicrm']['tags']);
    $tag1 = $this->ensureTagExists('smurfy');
    $tag2 = $this->ensureTagExists('smurfalicious');

    $this->callAPISuccess(
      'EntityTag',
      'create',
      [
        'entity_id' => $this->getContributionID(),
        'entity_table' => 'civicrm_contribution',
        'tag_id' => 'smurfy',
      ]
    );
    $this->callAPISuccess(
      'EntityTag',
      'create',
      [
        'entity_id' => $this->getContributionID(),
        'entity_table' => 'civicrm_contribution',
        'tag_id' => 'smurfalicious',
      ]
    );

    $smurfiestTags = wmf_civicrm_get_tag_names($this->getContributionID());
    $this->assertEquals(['smurfy', 'smurfalicious'], $smurfiestTags);

    $this->callAPISuccess('Tag', 'delete', ['id' => $tag1]);
    $this->callAPISuccess('Tag', 'delete', ['id' => $tag2]);
  }

  /**
   * Get the contribution id, creating it if need be.
   *
   * @return int
   */
  public function getContributionID(): int {
    if (!isset($this->ids['Contribution'][0])) {
      $this->createContribution([], 0);
    }
    return $this->ids['Contribution'][0];
  }

  /**
   * @throws \Civi\WMFException\WMFException
   * @throws \CiviCRM_API3_Exception
   */
  public function testSendThankYou(): void {
    variable_set('thank_you_add_civimail_records', 'false');
    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $this->assertEquals(1, TestMailer::countMailings());
    $sent = TestMailer::getMailing(0);
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertRegExp('/\$1.23/', $sent['html']);
    $this->assertNotRegExp('/Wikimedia Endowment/', $sent['html']);

    // 2021 email has name in the subject, switching to check for the content
    $this->assertRegExp('/donation is one more reason to celebrate./', $sent['subject']);

    // Check for tax information, DAF emails have this removed
    $this->assertRegExp('/tax-exempt number/', $sent['html']);
  }

  /**
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendThankYouAddCiviMailActivity(): void {
    variable_set('thank_you_add_civimail_records', 'true');
    variable_set('thank_you_civimail_rate', 1.0);
    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $activity = $this->callAPISuccess(
      'Activity',
      'getSingle',
      [
        'contact_id' => $this->ids['Contact'][0],
        'activity_type_id' => CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_type_id',
          'Email'
        ),
      ]
    );
    $this->assertEquals(1, TestMailer::countMailings());
    $sent = TestMailer::getMailing(0);
    $this->assertEquals($activity['details'], $sent['html']);
  }

  /**
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendEndowmentThankYou(): void {
    variable_set('thank_you_add_civimail_records', 'false');
    variable_set('thank_you_endowment_from_name', 'Endowment TY Sender');
    $this->createContribution(['financial_type_id:name' => 'Endowment Gift'], 0);
    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $this->assertEquals(1, TestMailer::countMailings());
    $sent = TestMailer::getMailing(0);
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals('Endowment TY Sender', $sent['from_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertRegExp('/\$1.23/', $sent['html']);
    $this->assertRegExp('/Wikimedia Endowment/', $sent['html']);

    // 2021 email has name in the subject, switching to check for the content
    $this->assertRegExp('/gift allows us to look far ahead.$/', $sent['subject']);
  }

  /**
   * Test that DAF (Donor Advised Fund) thank you mails do not have tax information
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendDAFThankYou(): void {
    variable_set('thank_you_add_civimail_records', 'false');

    // Set the gift source to Donor Advised Fund
    $custom_field_name = wmf_civicrm_get_custom_field_name('Gift Source');
    $this->callAPISuccess('Contribution', 'create', [
      'id' => $this->getContributionID(),
      $custom_field_name => 'Donor Advised Fund',
    ]);

    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $this->assertEquals(1, TestMailer::countMailings());
    $sent = TestMailer::getMailing(0);
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertRegExp('/\$1.23/', $sent['html']);
    // Check that tax information has been removed
    $this->assertNotRegExp('/tax-exempt number/', $sent['html']);
  }


  /**
   * Test that Stock gift thank you mails use the stock value amount
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendStockThankYou(): void {
    variable_set('thank_you_add_civimail_records', 'false');

    $stock_value = wmf_civicrm_get_custom_field_name('Stock Value');
    $description_of_stock = wmf_civicrm_get_custom_field_name('Description_of_Stock');
    $contribution_type_stock = wmf_civicrm_get_civi_id('contribution_type_id', 'Stock');
    $this->callAPISuccess('Contribution', 'create', [
      'id' => $this->getContributionID(),
      'financial_type_id' => $contribution_type_stock,
      $stock_value => '50.00',
      $description_of_stock => 'Test Stock Description',
    ]);

    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $this->assertEquals(1, TestMailer::countMailings());
    $sent = TestMailer::getMailing(0);
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertRegExp('/\$50.00/', $sent['html']);
    $this->assertRegExp('/\Test Stock Description/', $sent['html']);

  }

  /**
   * Helper function to protect test against cleanup issues.
   *
   * @param string $name
   *
   * @return int
   *
   */
  public function ensureTagExists(string $name): int {
    $tags = $this->callAPISuccess('EntityTag', 'getoptions', [
      'field' => 'tag_id',
    ]);
    if (in_array($name, $tags['values'], TRUE)) {
      return array_search($name, $tags['values'], TRUE);
    }
    $tag = $this->callAPISuccess(
      'Tag',
      'create',
      [
        'used_for' => 'civicrm_contribution',
        'name' => $name,
      ]
    );
    $this->callAPISuccess('Tag', 'getfields', ['cache_clear' => 1]);
    return (int) $tag['id'];
  }

  /**
   * Get the expected replyTo string.
   *
   * @return string
   */
  private function getExpectedReplyTo(): string {
    return "ty.{$this->ids['Contact'][0]}.{$this->ids['Contribution'][0]}" .
      '@donate.wikimedia.org';
  }

}

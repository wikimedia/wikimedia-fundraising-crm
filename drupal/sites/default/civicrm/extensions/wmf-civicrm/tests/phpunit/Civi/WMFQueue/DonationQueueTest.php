<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\CustomField;
use Civi\Api4\Address;
use Civi\Api4\Email;
use Civi\Api4\Generic\Result;
use Civi\Api4\OptionValue;
use Civi\Api4\Phone;
use Civi\Api4\Relationship;
use Civi\Api4\StateProvince;
use Civi\WMFHelper\ContributionRecur;
use SmashPig\Core\DataStores\PendingDatabase;
use Civi\WMFStatistic\ImportStatsCollector;

/**
 * @group queues
 */
class DonationQueueTest extends BaseQueueTestCase {

  protected string $queueName = 'test';

  protected string $queueConsumer = 'Donation';

  /**
   * Process an ordinary (one-time) donation message
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonation(): void {
    $message = $this->processDonationMessage([
      'gross' => 400,
      'original_gross' => 400,
      'original_currency' => 'USD',
    ]);
    $message2 = $this->processDonationMessage();
    $this->processContributionTrackingQueue();
    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '400.00',
      'fee_amount' => '0.00',
      'net_amount' => '400.00',
      'trxn_id' => 'GLOBALCOLLECT ' . $message['gateway_txn_id'],
      'source' => 'USD 400.00',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message['order_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message['gateway_txn_id'], $message['contribution_tracking_id']);

    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '476.17',
      'fee_amount' => '0.00',
      'net_amount' => '476.17',
      'trxn_id' => 'GLOBALCOLLECT ' . $message2['gateway_txn_id'],
      'source' => 'PLN 952.34',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message2['order_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message2['gateway_txn_id']);
  }

  /**
   * If 'invoice_id' is in the message, don't stuff that field with order_id
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonationInvoiceId(): void {
    $message = $this->processDonationMessage(
      ['invoice_id' => mt_rand()]
    );

    $expected = [
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Mouse, Mickey',
      'contact_id.display_name' => 'Mickey Mouse',
      'contact_id.first_name' => 'Mickey',
      'contact_id.last_name' => 'Mouse',
      'currency' => 'USD',
      'total_amount' => '476.17',
      'fee_amount' => '0.00',
      'net_amount' => '476.17',
      'trxn_id' => 'GLOBALCOLLECT ' . $message['gateway_txn_id'],
      'source' => 'PLN 952.34',
      'financial_type_id:label' => 'Cash',
      'contribution_status_id:label' => 'Completed',
      'payment_instrument_id:label' => 'Credit Card: Visa',
      'invoice_id' => $message['invoice_id'],
      'Gift_Data.Campaign' => 'Online Gift',
    ];
    $this->assertExpectedContributionValues($expected, $message['gateway_txn_id']);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign.
   */
  public function testDonationWithUTFCampaignOption(): void {
    $this->createCustomOption('Appeal', 'EmailCampaign1');
    $message = $this->processDonationMessage(['utm_campaign' => 'EmailCampaign1']);
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals('EmailCampaign1', $contribution['Gift_Data.Appeal']);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign not
   * already existing.
   */
  public function testDonationWithInvalidUTFCampaignOption(): void {
    $optionValue = 'made-up-option-value';
    $message = $this->processDonationMessage(['utm_campaign' => $optionValue]);
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals($optionValue, $contribution['Gift_Data.Appeal']);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign
   * previously disabled.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonationWithDisabledUTFCampaignOption(): void {
    $optionValue = 'disabled-option-value';
    $this->createCustomOption('Appeal', $optionValue);
    OptionValue::update(FALSE)
      ->addValue('is_active', FALSE)
      ->addWhere('value', '=', $optionValue)
      ->execute();
    $message = $this->processDonationMessage(['utm_campaign' => $optionValue]);
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals($optionValue, $contribution['Gift_Data.Appeal']);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign with
   * a different label.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonationWithDifferentLabelUTFCampaignOption(): void {
    $optionValue = 'new option';
    $this->createCustomOption('Appeal', $optionValue, 'different-label');
    $message = $this->processDonationMessage(['utm_campaign' => $optionValue]);
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals($optionValue, $contribution['Gift_Data.Appeal']);
    // Use single() to check that no other option values with this option exist.
    OptionValue::get(FALSE)->addWhere('value', '=', $optionValue)
      ->execute()->single();
  }

  /**
   * Process a donation message with some info from pending db
   *
   * @dataProvider getSparseMessages
   *
   * @param array $message
   * @param array $pendingMessage
   * @throws \SmashPig\Core\DataStores\DataStoreException|\SmashPig\Core\SmashPigException
   */
  public function testDonationSparseMessages(array $message, array $pendingMessage): void {
    $pendingMessage['order_id'] = $message['order_id'];
    PendingDatabase::get()->storeMessage($pendingMessage);
    $this->createCustomOption('Appeal', $pendingMessage['utm_campaign']);
    $this->processMessage($message, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($message);
    $this->assertEquals($pendingMessage['utm_campaign'], $contribution['Gift_Data.Appeal']);
    $pendingEntry = PendingDatabase::get()->fetchMessageByGatewayOrderId(
      $message['gateway'],
      $pendingMessage['order_id']
    );
    $this->assertNull($pendingEntry, 'Should have deleted pending DB entry');
  }

  /**
   * @throws \JsonException
   */
  public function testDuplicateHandling(): void {
    $message = $this->processDonationMessage();
    $message2 = $this->processDonationMessage([
      'contribution_tracking_id' => $message['contribution_tracking_id'],
      'order_id' => $message['order_id'],
      'date' => time(),
      'source_name' => 'SmashPig',
      'source_type' => 'listener',
      'source_version' => 'unknown',
    ]);
    $this->processContributionTrackingQueue();

    $originalOrderId = $message2['order_id'];
    $damagedRows = $this->getDamagedRows($message2);
    $this->assertCount(1, $damagedRows);
    $this->assertEquals($originalOrderId, $damagedRows[0]['order_id']);

    $expected = [
      // NOTE: This is a db-specific string, sqlite3 in this case, and
      // you'll have different formatting if using any other database.
      'original_date' => date('YmdHis', $message2['date']),
      'gateway' => $message2['gateway'],
      'order_id' => $originalOrderId,
      'gateway_txn_id' => $message2['gateway_txn_id'],
      'original_queue' => 'test',
    ];
    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $damagedRows[0][$key], 'Stored message had expected contents for key ' . $key);
    }

    $this->assertNotNull($damagedRows[0]['retry_date'], 'Should retry');
    $storedMessage = json_decode($damagedRows[0]['message'], TRUE, 512, JSON_THROW_ON_ERROR);
    $storedInvoiceId = $storedMessage['invoice_id'];
    unset($storedMessage['invoice_id'], $storedMessage['source_run_id'], $storedMessage['source_enqueued_time'], $storedMessage['source_host']);
    $this->assertEquals($message2, $storedMessage);

    $invoiceIdLen = strlen((string) $originalOrderId);
    $this->assertEquals(
      "$originalOrderId|dup-",
      substr($storedInvoiceId, 0, $invoiceIdLen + 5)
    );
  }

  public function getSparseMessages(): array {
    $amazonMessage = $this->loadMessage('sparse_donation_amazon');
    $amazonMessage['completion_message_id'] = 'amazon-' . $amazonMessage['order_id'];
    $dLocalMessage = $this->loadMessage('sparse_donation_dlocal');
    $dLocalMessage['completion_message_id'] = 'dlocal-' . $dLocalMessage['order_id'];

    return [
      'amazon' => [$amazonMessage, $this->loadMessage('pending_amazon')],
      'dlocal' => [$dLocalMessage, $this->loadMessage('pending_dlocal')],
    ];
  }

  /**
   * Create a custom option for the given field.
   *
   * @param string $fieldName
   * @param string $value
   * @param string $label
   *   Optional label (defaults to same as value).
   */
  public function createCustomOption(string $fieldName, string $value, string $label = ''): void {
    try {
      $appealField = CustomField::get(FALSE)
        ->addWhere('name', '=', $fieldName)
        ->execute()->first();
      $optionValue = OptionValue::get(FALSE)
        ->addWhere('option_group_id', '=', $appealField['option_group_id'])
        ->addWhere('value', '=', $value)
        ->execute()
        ->first();
      if (!$optionValue) {
        $this->createTestEntity('OptionValue', [
          'option_group_id' => $appealField['option_group_id'],
          'name' => $value,
          'label' => $label ?: $value,
          'value' => $value,
          'is_active' => 1,
        ]);
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to set up Option Value ' . $e->getMessage());
    }
  }

  /**
   * @param array $expected
   * @param int $gatewayTxnID
   * @param int|null $contributionTrackingID
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function assertExpectedContributionValues(array $expected, int $gatewayTxnID, ?int $contributionTrackingID = NULL): void {
    $returnFields = array_keys($expected);
    $returnFields[] = 'contact_id';

    $contribution = Contribution::get(FALSE)
      ->setSelect($returnFields)
      ->addWhere('contribution_extra.gateway_txn_id', '=', $gatewayTxnID)
      ->execute()->first();

    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key], 'unexpected value for ' . $key);
    }
    if ($contributionTrackingID) {
      $tracking = ContributionTracking::get(FALSE)
        ->addWhere('contribution_id', '=', $contribution['id'])
        ->execute()->first();
      $this->assertEquals($tracking['id'], $contributionTrackingID);
    }
  }

  /**
   * Test that existing on hold setting is retained.
   *
   * @throws \CRM_Core_Exception
   */
  public function testKeepOnHold(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'Agatha@wikimedia.org',
      'email_primary.on_hold' => TRUE,
    ]);
    // Double check it is set to on hold.
    Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('on_hold', '=', TRUE)
      ->execute()->single();

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => $this->createContributionRecur(['contact_id' => $contactID])['id'],
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Agatha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processMessage($msg);
    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->execute()->single();

    $this->assertEquals(1, $email['on_hold']);
    $this->assertEquals('agatha@wikimedia.org', $email['email']);

  }

  /**
   * Test that existing on hold setting is removed if the email changes.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRemoveOnHoldWhenUpdating() {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'Agatha@wikimedia.org',
      'email_primary.on_hold' => TRUE,
    ]);

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => $this->createContributionRecur(['contact_id' => $contactID])['id'],
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Pantha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processMessage($msg);
    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->execute()->single();

    $this->assertEquals('pantha@wikimedia.org', $email['email']);
    $this->assertEquals(0, $email['on_hold']);
  }

  /**
   * @see https://phabricator.wikimedia.org/T262232
   *
   * @throws \CRM_Core_Exception
   */
  public function testInvalidZipCodeDataFiltered(): void {
    $contact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
    ]);

    $msg = [
      'contact_id' => $contact['id'],
      'contact_hash' => $contact['hash'],
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => '1 Montgomery Street',
      'city' => 'San Francisco',
      'state_province' => 'CA',
      'country' => 'US',
      'email' => '',
      // Problematic postal code
      'postal_code' => '9412”£&*1',
    ];

    $this->processDonationMessage($msg);

    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute()->single();

    $this->assertEquals("94121", $address['postal_code']);
  }

  /**
   * If we get a contact ID and a bad email, leave the existing contact alone
   *
   * @throws \CRM_Core_Exception
   */
  public function testImportWithContactIDAndBadEmail(): void {
    $email = 'boo-boo' . mt_rand() . '@example.org';
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'email_primary.email' => $email,
      'address_primary.country_id.iso_code' => 'FR',
      'address_primary.street_address' => '777 Trompe L\'Oeil Boulevard',
    ], 'existing');

    $msg = [
      'contact_id' => $existingContact['id'],
      'first_name' => 'Lex',
      'contact_hash' => $existingContact['hash'],
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'totally.different@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processMessage($msg);
    $contribution = $this->getContributionForMessage($msg);
    $this->assertNotEquals($existingContact['id'], $contribution['contact_id']);
    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $existingContact['id'])
      ->execute()->single();
    $this->assertNotEquals($msg['street_address'], $address['street_address']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testPhoneImport(): void {
    $phoneNumber = '555-555-5555';
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'phone' => $phoneNumber,
    ];

    $this->processDonationMessage($msg);
    $contribution = $this->getContributionForMessage($msg);

    $phone = Phone::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->addSelect('location_type_id', 'phone', 'is_primary', 'phone_type_id:name')
      ->execute()->single();

    $this->assertEquals($phoneNumber, $phone['phone']);
    $this->assertEquals(1, $phone['is_primary']);
    $this->assertEquals(\CRM_Core_BAO_LocationType::getDefault()->id, $phone['location_type_id']);
    $this->assertEquals('Phone', $phone['phone_type_id:name']);
  }

  /**
   * Test that endowment donation is imported with the right fields for
   * restrictions, gift_source, and financial_type_id
   * see: https://phabricator.wikimedia.org/T343756
   */
  public function testEndowmentDonationImport(): void {
    $contactID = $this->createIndividual();

    $msg = [
      'contact_id' => $contactID,
      'contribution_recur_id' => NULL,
      'currency' => 'USD',
      'date' => '2014-01-01 00:00:00',
      'effort_id' => 2,
      'email' => 'Agatha@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => 2.34,
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'utm_medium' => 'endowment',
    ];
    $this->processDonationMessage($msg);
    $contribution = $this->getContributionForMessage($msg);

    $this->assertEquals("Endowment Fund", $contribution['Gift_Data.Fund']);
    $this->assertEquals("Online Gift", $contribution['Gift_Data.Campaign']);
    $this->assertEquals('Endowment Gift', $contribution['financial_type_id:name']);
  }

  /**
   * If we get a contact ID and a bad email, leave the existing contact alone
   */
  public function testImportWithContactExisting(): void {
    $existingContact = $this->createTestEntity('Contact', [
      'contact_type' => 'Individual',
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'email_primary.email' => 'dupe@example.org',
    ]);

    $msg = [
      'first_name' => 'Test',
      'last_name' => 'Mouse',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'dupe@example.org',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
    $this->processDonationMessage($msg);
    $contribution = $this->getContributionForMessage($msg);
    $this->assertEquals($existingContact['id'], $contribution['contact_id']);
  }

  public function testRecurringNoToken() {
    // need to set up a recurring message recurring=1 but there is no entry in the token DB
    $msg = [
      'first_name' => 'Lex',
      'last_name' => 'Mouse',
      'currency' => 'USD',
      'date' => '2017-01-01 00:00:00',
      'invoice_id' => mt_rand(),
      'email' => 'totally.different@example.com',
      'country' => 'US',
      'street_address' => '123 42nd St. #321',
      'gateway' => 'Ingenico',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.25',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'recurring' => 1,
      'recurring_payment_token' => mt_rand(),
      'user_ip' => '123.232.232',
    ];
    $this->processDonationMessage($msg);
    $contribution = $this->getContributionForMessage($msg);
    $this->assertNotEmpty($contribution['contribution_recur_id']);
  }

  /**
   * Test importing messages using variations form messageProvider data-provider.
   *
   * @dataProvider messageProvider
   *
   * @param array $msg
   * @param array $expected
   *
   * @throws \CRM_Core_Exception
   */
  public function testProcessMessage(array $msg, array $expected): void {
    if (!empty($msg['contribution_recur_id'])) {
      // Create this here - the fixtures way was not reliable
      $msg['contact_id'] = $this->createIndividual();
      $msg['contribution_recur_id'] = $this->createContributionRecur(['contact_id' => $msg['contact_id']])['id'];
    }
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);
    $this->processContributionTrackingQueue();
    $this->assertComparable($expected['contribution'], $contribution);

    if (!empty($expected['contact'])) {
      $contact = Contact::get(FALSE)->addWhere('id', '=', $contribution['contact_id'])
        ->addSelect('*', 'prefix_id:name', 'suffix_id:name', 'custom.*', 'financial_type_id:name', 'payment_instrument_id:name')
        ->execute()->single();
      $renamedFields = ['prefix' => 1, 'suffix' => 1];
      $this->assertEquals(array_diff_key($expected['contact'], $renamedFields), array_intersect_key($contact, $expected['contact']), print_r(array_intersect_key($contact, $expected['contact']), TRUE) . " does not match " . print_r(array_diff_key($expected['contact'], $renamedFields), TRUE));
      foreach (array_keys($renamedFields) as $renamedField) {
        if (isset($expected['contact'][$renamedField])) {
          $this->assertEquals(civicrm_api3('OptionValue', 'getvalue', [
            'value' => $contact[$renamedField . '_id'],
            'option_group_id' => 'individual_' . $renamedField,
            'return' => 'name',
          ]), $expected['contact'][$renamedField]);
        }
      }
    }

    if (!empty($expected['address'])) {
      $address = Address::get(FALSE)
        ->addWhere('contact_id', '=', $contribution['contact_id'])
        ->addSelect('country_id:name', 'state_province_id:name', 'state_province_id', 'city', 'postal_code', 'street_address', 'geo_code_1', 'geo_code_2', 'timezone')
        ->execute()->first();
      $this->assertComparable($expected['address'], $address);
    }
  }

  /**
   * Data provider for import test.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function messageProvider(): array {
    return [
      'Minimal contribution' => [
        'message' => $this->getMinimalImportData(7690),
        'expected' => [
          'contribution' => $this->getBaseContribution(7690),
        ],
      ],
      'Minimal contribution with comma thousand separator' => [
        'message' => [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 8907,
          'gross' => '1,000.23',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
        ],
        'expected' => [
          'contribution' => [
            'contribution_status_id:name' => 'Completed',
            'currency' => 'USD',
            'fee_amount' => 0.00,
            'total_amount' => '1,000.23',
            'net_amount' => '1,000.23',
            'payment_instrument_id:name' => 'Credit Card: Visa',
            'receipt_date' => '',
            'receive_date' => '2012-05-01 00:00:00',
            'source' => 'USD 1,000.23',
            'trxn_id' => "TEST_GATEWAY 8907",
            'financial_type_id:name' => 'Cash',
            'check_number' => '',
          ],
        ],
      ],
      'over-long city' => [
        'message' => array_merge(
          $this->getMinimalImportData(99998888),
          ['city' => 'This is just stupidly long and I do not know why I would enter something this crazily long into a field']
        ),
        'expected' => [
          'contribution' => $this->getBaseContribution(99998888),
        ],
      ],
      'Maximal contribution' => [
        'message' => [
          'check_number' => 56565656,
          'currency' => 'USD',
          'date' => '2024-03-01 00:00:00',
          'direct_mail_appeal' => 'Spontaneous Donation',
          'do_not_email' => '1',
          'do_not_mail' => '1',
          'do_not_phone' => '1',
          'do_not_sms' => '1',
          'do_not_solicit' => '1',
          'email' => 'mouse@wikimedia.org',
          'first_name' => 'First',
          'fee' => 0.03,
          'language' => 'en',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 67676767,
          'gateway_status' => 'P',
          'gift_source' => 'Legacy Gift',
          'gross' => '1.23',
          'import_batch_number' => '4321',
          'is_opt_out' => '1',
          'last_name' => 'Last',
          'middle_name' => 'Middle',
          'no_thank_you' => 'no forwarding address',
          'prefix_id:label' => 'Mr.',
          'suffix_id:label' => 'Sr.',
          'payment_method' => 'check',
          'stock_description' => 'Long-winded prolegemenon',
          'thankyou_date' => '2024-04-01',
          'fiscal_number' => 'AAA11223344',
        ],
        'expected' => [
          'contact' => [
            'do_not_email' => '1',
            'do_not_mail' => '1',
            'do_not_phone' => '1',
            'do_not_sms' => '1',
            'first_name' => 'First',
            'is_opt_out' => '1',
            'last_name' => 'Last',
            'middle_name' => 'Middle',
            'prefix' => 'Mr.',
            'suffix' => 'Sr.',
            'preferred_language' => 'en_US',
            'legal_identifier' => 'AAA11223344',
            'Communication.do_not_solicit' => '1',
            'wmf_donor.total_2023' => 0,
            'wmf_donor.total_2024' => 1.23,
            'wmf_donor.number_donations' => 1,
            'wmf_donor.first_donation_date' => '2024-03-01 00:00:00',
            'wmf_donor.last_donation_date' => '2024-03-01 00:00:00',
            'wmf_donor.last_donation_usd' => '1.23',
            'wmf_donor.lifetime_usd_total' => '1.23',
            'wmf_donor.total_2023_2024' => 1.23,
          ],
          'contribution' => [
            'address_id' => '',
            'amount_level' => '',
            'campaign_id' => '',
            'cancel_date' => '',
            'cancel_reason' => '',
            'check_number' => 56565656,
            'contribution_page_id' => '',
            'contribution_recur_id' => '',
            'contribution_status_id:name' => 'Completed',
            'currency' => 'USD',
            'fee_amount' => 0.03,
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => 1.20,
            'payment_instrument_id:name' => 'Check',
            'receipt_date' => '',
            'receive_date' => '2024-03-01 00:00:00',
            'source' => 'USD 1.23',
            'thankyou_date' => '2024-04-01 00:00:00',
            'total_amount' => '1.23',
            'trxn_id' => "TEST_GATEWAY 67676767",
            'financial_type_id:name' => 'Cash',
            'Gift_Data.Appeal' => 'Spontaneous Donation',
            'Gift_Information.import_batch_number' => '4321',
            'Gift_Data.Campaign' => 'Legacy Gift',
            'contribution_extra.gateway' => 'test_gateway',
            'contribution_extra.gateway_txn_id' => '67676767',
            'contribution_extra.gateway_status_raw' => 'P',
            'contribution_extra.no_thank_you' => 'no forwarding address',
            'Stock_Information.Description_of_Stock' => 'Long-winded prolegemenon',
          ],
        ],
      ],
      'Invalid language suffix for valid short lang' => [
        'data' => [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 444444,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
          'language' => 'en_ZW',
          'prefix_id:label' => 'Mr.',
          'suffix_id:label' => 'Sr.',
        ],
        'expected' => [
          'contact' => [
            'preferred_language' => 'en_US',
            'prefix_id:name' => 'Mr.',
            'suffix_id:name' => 'Sr.',
          ],
          'contribution' => $this->getBaseContribution(444444),
        ],
      ],
      'Full name' => [
        'message' => [
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 999999,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
          'language' => 'en_US',
          'full_name' => 'Dr. Martin Luther Mouse, Jr.',
        ],
        'expected' => [
          'contact' => [
            'prefix' => 'Dr.',
            'first_name' => 'Martin',
            'middle_name' => 'Luther',
            'last_name' => 'Mouse',
            'suffix' => 'Jr',
          ],
          'contribution' => $this->getBaseContribution(999999),
        ],
      ],
      'Organization contribution' => [
        'message' => [
          'contact_type' => 'Organization',
          'currency' => 'USD',
          'date' => '2012-03-01 00:00:00',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 232323,
          'gross' => '1.23',
          'organization_name' => 'The Firm',
          'org_contact_name' => 'Test Name',
          'org_contact_title' => 'Test Title',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
        ],
        'expected' => [
          'contact' => [
            'Organization_Contact.Name' => 'Test Name',
            'Organization_Contact.Title' => 'Test Title',
          ],
          'contribution' => [
            'address_id' => '',
            'amount_level' => '',
            'campaign_id' => '',
            'cancel_date' => '',
            'cancel_reason' => '',
            'check_number' => '',
            'contribution_page_id' => '',
            'contribution_recur_id' => '',
            'contribution_status_id:name' => 'Completed',
            'currency' => 'USD',
            'fee_amount' => 0.00,
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => '1.23',
            'payment_instrument_id:name' => 'Credit Card: Visa',
            'receipt_date' => '',
            'receive_date' => '2012-03-01 00:00:00',
            'source' => 'USD 1.23',
            'thankyou_date' => '',
            'total_amount' => '1.23',
            'trxn_id' => "TEST_GATEWAY 232323",
            'financial_type_id:name' => 'Cash',
          ],
        ],
      ],
      'Subscription payment' => [
        'message' => [
          'contact_id' => TRUE,
          'contribution_recur_id' => TRUE,
          'currency' => 'USD',
          'date' => '2014-01-01 00:00:00',
          'effort_id' => 2,
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 5555555,
          'gross' => 2.34,
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
        ],
        'expected' => [
          'contribution' => [
            'address_id' => '',
            'amount_level' => '',
            'campaign_id' => '',
            'cancel_date' => '',
            'cancel_reason' => '',
            'check_number' => '',
            'contact_id' => TRUE,
            'contribution_page_id' => '',
            'contribution_recur_id' => TRUE,
            'contribution_status_id:name' => 'Completed',
            'currency' => 'USD',
            'fee_amount' => 0.00,
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => 2.34,
            'payment_instrument_id:name' => 'Credit Card: Visa',
            'receipt_date' => '',
            'receive_date' => '2014-01-01 00:00:00',
            'source' => 'USD ' . 2.34,
            'thankyou_date' => '',
            'total_amount' => 2.34,
            'trxn_id' => "TEST_GATEWAY 5555555",
            'financial_type_id' => ContributionRecur::getFinancialTypeForFirstContribution(),
          ],
        ],
      ],
      'Country-only address' => [
        'message' => array_merge(
          $this->getMinimalImportData(4567890),
          [
            'country' => 'FR',
          ]
        ),
        'expected' => [
          'contribution' => $this->getBaseContribution(4567890),
          'address' => [
            'country_id:name' => 'FR',
          ],
        ],
      ],
      'Strip duff characters' => [
        'message' => array_merge(
          $this->getMinimalImportData(345345),
          [
            'first_name' => 'Baa   baa black sheep',
          ]
        ),
        'expected' => [
          'contact' => [
            'first_name' => 'Baa baa black sheep',
          ],
          'contribution' => $this->getBaseContribution(345345),
        ],
      ],
      'white_space_cleanup' => [
        'message' => array_merge(
          $this->getMinimalImportData(494949),
          [
            // The multiple spaces & trailing ideographic space should go.
            // Internally I have set it to reduce multiple ideographic space to only one.
            // However, I've had second thoughts about my earlier update change to
            // convert them as they are formatted differently & the issue was not the
            // existence of them but the strings of several of them in a row.
            'first_name' => 'Baa   baa' . html_entity_decode('&#x3000;')
            . html_entity_decode('&#x3000;')
            . 'black sheep' . html_entity_decode('&#x3000;'),
            'middle_name' => '  Have &nbsp; you any wool',
            'last_name' => ' Yes sir yes sir ' . html_entity_decode('&nbsp;') . ' three bags full',
          ]
        ),
        'expected' => [
          'contact' => [
            'first_name' => 'Baa baa' . html_entity_decode('&#x3000;') . 'black sheep',
            'middle_name' => 'Have you any wool',
            'last_name' => 'Yes sir yes sir three bags full',
            'display_name' => 'Baa baa'
            . html_entity_decode('&#x3000;')
            . 'black sheep Yes sir yes sir three bags full',
          ],
          'contribution' => $this->getBaseContribution(494949),
        ],
      ],
      'ampersands' => [
        'message' => array_merge(
          $this->getMinimalImportData(232323),
          [
            // The multiple spaces & trailing ideographic space should go.
            // Internally I have set it to reduce multiple ideographic space to only one.
            // However, I've had second thoughts about my earlier update change to
            // convert them as they are formatted differently & the issue was not the
            // existence of them but the strings of several of them in a row.
            'first_name' => 'Jack &amp; Jill',
            'middle_name' => 'Jack &Amp; Jill',
            'last_name' => 'Jack & Jill',
          ]
        ),
        'expected' => [
          'contact' => [
            'first_name' => 'Jack & Jill',
            'middle_name' => 'Jack & Jill',
            'last_name' => 'Jack & Jill',
            'display_name' => 'Jack & Jill Jack & Jill',
          ],
          'contribution' => $this->getBaseContribution(232323),
        ],
      ],
      'US address import is geocoded' => [
        'message' => [
          'city' => 'Somerville',
          'country' => 'US',
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 9111,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
          'postal_code' => '02144',
          'state_province' => 'MA',
          'street_address' => '1 Davis Square',
        ],
        'expected' => [
          'contribution' => $this->getBaseContribution(9111),
          'address' => [
            'country_id:name' => 'US',
            'state_province_id' => StateProvince::get(FALSE)
              ->addWhere('abbreviation', '=', 'MA')
              ->addWhere('country_id.iso_code', '=', 'US')
              ->execute()->first()['id'],
            'city' => 'Somerville',
            'postal_code' => '02144',
            'street_address' => '1 Davis Square',
            'geo_code_1' => '42.399546',
            'geo_code_2' => '-71.12165',
            'timezone' => 'UTC-5',
          ],
        ],
      ],
      'opt in (yes)' => [
        'message' => array_merge(
          $this->getMinimalImportData(181818),
          [
            'opt_in' => '1',
          ]
        ),
        'expected' => [
          'contact' => [
            'Communication.opt_in' => '1',
          ],
          'contribution' => $this->getBaseContribution(181818),
        ],
      ],
      'opt in (no)' => [
        'message' => array_merge(
          $this->getMinimalImportData(2982989),
          [
            'opt_in' => '0',
          ]
        ),
        'expected' => [
          'contact' => [
            'Communication.opt_in' => '0',
          ],
          'contribution' => $this->getBaseContribution(2982989),
        ],
      ],
      'opt in (empty)' => [
        'message' => array_merge(
          $this->getMinimalImportData(535251),
          [
            'opt_in' => '',
          ]
        ),
        'expected' => [
          'contact' => [
            'Communication.opt_in' => NULL,
          ],
          'contribution' => $this->getBaseContribution(535251),
        ],
      ],
      "'employer' field populated and mapped correctly" => [
        'message' => array_merge(
          $this->getMinimalImportData(74747),
          [
            'employer' => 'Wikimedia Foundation',
          ]
        ),
        'expected' => [
          'contact' => ['Communication.Employer_Name' => 'Wikimedia Foundation'],
          'contribution' => $this->getBaseContribution(74747),
        ],
      ],
      'Endowment Gift, specified in utm_medium' => [
        'message' => [
          'currency' => 'USD',
          'date' => '2018-07-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'first_name' => 'First',
          'fee' => '0.03',
          'language' => 'en_US',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 123789,
          'gateway_status' => 'P',
          'gross' => '1.23',
          'last_name' => 'Mouse',
          'middle_name' => 'Middle',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
          'utm_medium' => 'endowment',
        ],
        'expected' => [
          'contribution' => [
            'address_id' => '',
            'amount_level' => '',
            'campaign_id' => '',
            'cancel_date' => '',
            'cancel_reason' => '',
            'check_number' => '',
            'contribution_page_id' => '',
            'contribution_recur_id' => '',
            'contribution_status_id:name' => 'Completed',
            'currency' => 'USD',
            'fee_amount' => 0.03,
            'invoice_id' => '',
            'is_pay_later' => '',
            'is_test' => '',
            'net_amount' => 1.20,
            'payment_instrument_id:name' => 'Credit Card: Visa',
            'receipt_date' => '',
            'receive_date' => '2018-07-01 00:00:00',
            'source' => 'USD 1.23',
            'total_amount' => '1.23',
            'trxn_id' => "TEST_GATEWAY 123789",
            'financial_type_id:name' => 'Endowment Gift',
          ],
          'contribution_custom_values' => [
            'contribution_extra.gateway' => 'test_gateway',
            'contribution_extra.gateway_txn_id' => '123789',
            'contribution_extra.gateway_status_raw' => 'P',
          ],
        ],
      ],
      'Language es-419' => [
        'message' => array_merge(
          $this->getMinimalImportData(9599),
          [
            'language' => 'es-419',
          ]
        ),
        [
          'contact' => [
            'preferred_language' => 'es_MX',
          ],
          'contribution' => $this->getBaseContribution(9599),
        ],
      ],
      'Unsupported 3 char language code' => [
        'message' => array_merge(
          $this->getMinimalImportData(887766),
          [
            'language' => 'shn',
          ]
        ),
        'expected' => [
          'contact' => [
            'preferred_language' => 'en_US',
          ],
          'contribution' => $this->getBaseContribution(887766),
        ],
      ],
      'Unicode middle initial in full_name' => [
        // Unicode middle initial in full_name is not mangled
        // for now, workaround sticks it on last name (which
        // may be the right thing to do for some cases)
        'message' => [
          'full_name' => 'Someone Ó Something',
          'country' => 'US',
          'currency' => 'USD',
          'date' => '2012-05-01 00:00:00',
          'email' => 'mouse@wikimedia.org',
          'gateway' => 'test_gateway',
          'gateway_txn_id' => 7272727,
          'gross' => '1.23',
          'payment_method' => 'cc',
          'payment_submethod' => 'visa',
        ],
        [
          'contact' => [
            'first_name' => 'Someone',
            'last_name' => 'Ó Something',
          ],
          'contribution' => $this->getBaseContribution(7272727),
        ],
      ],
    ];
  }

  /**
   * Assert that 2 arrays are the same in all the ways that matter :-).
   *
   * This has been written for a specific test & will probably take extra work
   * to use more broadly.
   *
   * @param array $expected
   * @param array $actual
   */
  public function assertComparable(array $expected, array $actual) {
    foreach ($expected as $field => $value) {
      if (in_array($field, ['total_amount', 'source', 'net_amount', 'fee_amount'], TRUE)) {
        $value = str_replace(',', '', $value);
      }
      $this->assertEquals($value, $actual[$field], 'Expected match on field : ' . $field);
    }
  }

  protected function getMinimalImportData($gateway_txn_id): array {
    return [
      'currency' => 'USD',
      'date' => '2012-05-01 00:00:00',
      'email' => 'mouse@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => $gateway_txn_id,
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    ];
  }

  /**
   * Get the basic array of contribution data.
   *
   * @param string $gateway_txn_id
   *
   * @return array
   */
  protected function getBaseContribution(string $gateway_txn_id): array {
    return [
      'campaign_id' => '',
      'cancel_date' => '',
      'cancel_reason' => '',
      'check_number' => '',
      'contribution_page_id' => '',
      'contribution_recur_id' => '',
      'contribution_status_id:name' => 'Completed',
      'currency' => 'USD',
      'fee_amount' => 0.00,
      'invoice_id' => '',
      'is_pay_later' => '',
      'is_test' => '',
      'net_amount' => '1.23',
      'payment_instrument_id:name' => 'Credit Card: Visa',
      'receipt_date' => '',
      'receive_date' => '2012-05-01 00:00:00',
      'source' => 'USD 1.23',
      'thankyou_date' => '',
      'total_amount' => '1.23',
      'trxn_id' => "TEST_GATEWAY {$gateway_txn_id}",
      'financial_type_id:name' => 'Cash',
    ];
  }

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function testMessageImportStatsProcessingRatesGenerated(): void {
    $importStatsCollector = ImportStatsCollector::getInstance();
    $emptyStats = $importStatsCollector->getAllStats();
    $this->assertEmpty($emptyStats);
    $this->processMessageWithoutQueuing($this->getDonationMessage(), 'Donation');
    $notEmptyStats = $importStatsCollector->getAllStats();
    $this->assertNotEmpty($notEmptyStats);
    // Check we have running times for a insertContribution after each import
    $contribution_insert_stats = $importStatsCollector->get("*timer.message_contribution_insert*");

    $this->assertArrayHasKey('start', $contribution_insert_stats);
    $this->assertArrayHasKey('end', $contribution_insert_stats);
    $this->assertArrayHasKey('diff', $contribution_insert_stats);
  }

  /**
   * Test creating an address with void data does not create an address.
   */
  public function testAddressImportVoidData(): void {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $addresses = $this->getMouseHouses();
    $this->assertCount(0, $addresses);
  }

  /**
   * Test creating an address not use void data.
   *
   * @dataProvider getVoidValues
   *
   * @param string|int $voidValue
   * @throws \CRM_Core_Exception
   */
  public function testAddressImportSkipVoidData($voidValue) {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'really cool place',
      'postal_code' => $voidValue,
      'city' => $voidValue,
      'country' => 'US',
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $address = $this->getMouseHouses()->single();
    $this->assertTrue(!isset($address['city']));
    $this->assertTrue(!isset($address['postal_code']));
  }

  /**
   * Get values which should not be stored to the DB.
   *
   * @return array
   */
  public function getVoidValues(): array {
    return [
      ['0'],
      [0],
      ['NoCity'],
      ['City/Town'],
    ];
  }

  /**
   * Test creating an address with void data does not create an address.
   *
   * In this case the contact already exists.
   */
  public function testAddressImportVoidDataContactExists() {
    $msg = [
      'contact_id' => $this->createIndividual(),
      'currency' => 'USD',
      'date' => time(),
      'last_name' => 'Mouse',
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'street_address' => 'N0NE PROVIDED',
      'postal_code' => 0,
    ];

    $this->processMessage($msg, 'Donation', 'test');
    $this->assertCount(0, $this->getMouseHouses());
  }

  public function getMouseHouses(): Result {
    try {
      return Address::get(FALSE)
        ->addWhere('contact_id.last_name', '=', 'Mouse')
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('Failed to retrieve addresses');
    }
  }

  /**
   * @dataProvider employerRelationDataProvider
   *
   * @param string $sourceType
   * @param bool $isUpdate
   * @param ?bool $expected
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testIndicatesEmployerProvidedByDonor(string $sourceType, bool $isUpdate, ?bool $expected) {
    $orgContact = $this->createTestEntity('Contact', [
      'organization_name' => 'The Firm',
      'contact_type' => 'Organization',
    ], 'employer');

    $contactParams = [
      'first_name' => 'Philip',
      'last_name' => 'Mouse',
    ];
    if ($isUpdate) {
      $existingContactID = $this->createIndividual(array_merge($contactParams, [
        'contact_type' => 'Individual',
        'employer_id' => $orgContact['id'],
      ]));
      Email::create(FALSE)
        ->setValues([
          'contact_id' => $existingContactID,
          'email' => 'pmason@puritanfoods.com',
        ])
        ->execute();
    }

    $msg = array_merge(
      $contactParams, $this->getMinimalImportData(mt_rand())
    );
    $msg['email'] = 'pmason@puritanfoods.com';
    $msg['source_type'] = $sourceType;
    $msg['employer_id'] = $orgContact['id'];

    $this->processMessageWithoutQueuing($msg, 'Donation');
    $contribution = $this->getContributionForMessage($msg);
    $relationship = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contribution['contact_id'])
      ->addWhere('contact_id_b', '=', $orgContact['id'])
      ->addWhere('relationship_type_id:name', '=', 'Employee of')
      ->addWhere('is_active', '=', 1)
      ->addSelect('custom.*')
      ->execute();

    $this->assertCount(1, $relationship);
    $this->assertEquals(
      $expected, $relationship->first()['Relationship_Metadata.provided_by_donor']
    );
    $this->assertContactValue($contribution['contact_id'], 'The Firm', 'employer_id.display_name');
    // @todo test with active relationship to other employer
  }

  /**
   * Data provider for employer metadata tests
   * @return array[]
   */
  public function employerRelationDataProvider(): array {
    return [
      'Should create new donor with employer, provided_by_donor = TRUE' => [
        'payments', FALSE, TRUE,
      ],
      'Should update donor with employer relationship, provided_by_donor = TRUE' => [
        'payments', TRUE, TRUE,
      ],
      'Should create new donor with employer, provided_by_donor not set' => [
        'direct', FALSE, NULL,
      ],
      'Should update donor with employer relationship, provided_by_donor not set' => [
        'direct', TRUE, NULL,
      ],
    ];
  }

}

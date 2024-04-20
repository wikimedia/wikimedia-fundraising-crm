<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\CustomField;
use Civi\Api4\Address;
use Civi\Api4\Email;
use Civi\Api4\OptionValue;
use Civi\Api4\Phone;
use SmashPig\Core\DataStores\DataStoreException;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\SmashPigException;

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
   *
   * @throws DataStoreException
   * @throws SmashPigException
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
   * @throws \Civi\API\Exception\UnauthorizedException
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

}

<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\CustomField;
use Civi\Api4\OptionValue;
use SmashPig\Core\DataStores\PendingDatabase;

/**
 * @group queues
 */
class DonationQueueTest extends BaseQueue {

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
   *
   * @throws \CRM_Core_Exception
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
   * @throws \Civi\WMFException\WMFException
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
   * @throws \SmashPig\Core\DataStores\DataStoreException
   * @throws \SmashPig\Core\SmashPigException
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

}

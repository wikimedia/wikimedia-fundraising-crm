<?php

namespace Civi;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ExchangeRate;
use Civi\Api4\WMFQueue;
use Civi\ExchangeRates\ExchangeRatesException;
use CRM_ExchangeRates_BAO_ExchangeRate;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

trait WMFQueueTrait {

  /**
   * Process anything in the contribution tracking queue.
   */
  public function processContributionTrackingQueue(): void {
    $this->processQueue('contribution-tracking', 'ContributionTracking');
  }

  /**
   * @return void
   */
  public function processDonationsQueue(): void {
    $this->processQueue('donations', 'Donation');
  }

  /**
   * Process donation, using defaults plus any passed in values.
   *
   * Note that it is intended that when using this method you only
   * pass in relevant values to make it easier to distinguish the
   * meaningful aspects of the test.
   *
   * @param array $values
   *   Values to use in the message. These will be augmented with some defaults unless isAddDefaults is FALSE.
   * @param bool $isAddDefaults
   *   TRUE to add additional values. FALSE to ensure that only required fields are added.
   *   If you wish to simulate not having a required field you need to set it to an empty string.
   * @return array
   */
  protected function processDonationMessage(array $values = [], bool $isAddDefaults = TRUE): array {
    $donation_message = $this->getDonationMessage($values, $isAddDefaults);
    $this->processMessage($donation_message, 'Donation', 'test');
    return $donation_message;
  }

  /**
   * @param array $values
   *   Any values to be used instead of the loaded ones.
   * @param bool $isAddDefaults
   *   TRUE to add additional values. FALSE to ensure that only required fields are added.
   *   If you wish to simulate not having a required field you need to set it to an empty string.
   * @param array $exchangeRates
   *   Exchange rates to set, defaults to setting USD to 1
   *   and the loaded currency to 3.
   *
   * @return array
   */
  public function getDonationMessage(array $values = [], bool $isAddDefaults = TRUE, array $exchangeRates = ['USD' => 1, 'PLN' => 0.5]): array {
    $message = $isAddDefaults ? $this->getBasicDonationMessage() : [];
    $message['gateway_txn_id'] = mt_rand();
    $contributionTrackingID = mt_rand();
    // Add required values, if not passed in.
    $message += [
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
      'date' => time(),
      'payment_method' => 'cc',
      'payment_submethod' => empty($message['payment_method']) ? 'cc' : '',
      'currency' => 'USD',
      'gateway' => 'test_gateway',
      'gross' => '1.23',
    ];
    $this->setExchangeRatesForMessage($exchangeRates, $message);
    return array_merge($message, $values);
  }

  /**
   * @param array $exchangeRates
   * @param array $message
   *
   * @return void
   */
  public function setExchangeRatesForMessage(array $exchangeRates, array $message): void {
    if ($exchangeRates) {
      if (isset($exchangeRates['*']) && !isset($exchangeRates[$message['currency']])) {
        $exchangeRates[$message['currency']] = $exchangeRates['*'];
      }
      unset($exchangeRates['*']);
      $this->setExchangeRates($message['date'], $exchangeRates);
    }
  }

  /**
   * Get a basic donation message.
   *
   * Note that this is copied from the donation.json but added here to make the values
   * more visible when debugging / writing tests.
   *
   * @return array
   */
  protected function getBasicDonationMessage(): array {
    return [
      'city' => 'cc',
      'comment' => '',
      'contribution_tracking_id' => '441',
      'country' => 'IL',
      'currency' => 'PLN',
      'date' => 1234567,
      'email' => 'test+201@local.net',
      'fee' => '0',
      'first_name' => 'Mickey',
      'gateway_account' => 'default',
      'gateway' => 'globalcollect',
      'gateway_txn_id' => '3611204184',
      'gross' => '952.34',
      'language' => 'en',
      'last_name' => 'Mouse',
      'middle_name' => '',
      'optout' => '1',
      'original_currency' => 'PLN',
      'original_gross' => '952.34',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'postal_code' => '11122',
      'premium_language' => 'en',
      'response' => 'Original Response Status (pre-SET_PAYMENT) => 600',
      'state_province' => 'Haifa',
      'street_address' => 'street address that is longer than 128 characters long because sometimes you need a little field size and sometimes you need a lot',
      'supplemental_address_1' => '',
      'supplemental_address_2' => '',
      'user_ip' => '127.0.0.2',
      'utm_campaign' => '',
      'utm_medium' => '',
      'utm_source' => '..cc',
    ];
  }

  /**
   * @param $currency
   * @param $amount
   *
   * @return float
   */
  protected function getConvertedAmount($currency, $amount): float {
    try {
      return (float)ExchangeRate::convert(FALSE)
        ->setFromCurrency($currency)
        ->setFromAmount($amount)
        ->execute()
        ->first()['amount'];
    }
    catch (ExchangeRatesException $e) {
      $this->fail('Exchange rate conversion failed: ' . $e->getMessage());
    }
  }

  /**
   * @param string $currency
   * @param float $amount
   * @return float
   */
  protected function getConvertedAmountRounded(string $currency, float $amount): float {
    return $this->round($this->getConvertedAmount($currency, $amount), $currency);
  }

  /**
   * @param float $amount
   * @param string $currency
   *
   * @return string
   */
  protected function round(float $amount, string $currency = 'USD'): string {
    return CurrencyRoundingHelper::round($amount, $currency);
  }

  /**
   * Process the given queue.
   *
   * @param string $queueName
   * @param string $queueConsumer
   *
   * @return array|null
   */
  public function processQueue(string $queueName, string $queueConsumer): ?array {
    try {
      return WMFQueue::consume()
        ->setQueueName($queueName)
        ->setQueueConsumer($queueConsumer)
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to process ' . $queueName . ' with consumer ' . $queueConsumer . "\n" . $e->getMessage());
    }
  }

  /**
   * Process the given queue.
   *
   * @param array $message
   * @param string|null $queueConsumer
   * @param string|null $queueName
   *
   * @return array|null
   * @noinspection PhpUnhandledExceptionInspection
   * @noinspection PhpDocMissingThrowsInspection
   */
  public function processMessage(array $message, ?string $queueConsumer = NULL, ?string $queueName = NULL): ?array {
    $queueName = $queueName ?: $this->queueName;
    $queueConsumer = $queueConsumer ?: $this->queueConsumer;
    QueueWrapper::push($queueName, $message);
    $result = $this->processQueue($queueName, $queueConsumer);
    if (!empty($message['gateway_txn_id']) && !empty($message['gateway'])) {
      try {
        // Register the ids for clean up, if we can.
        $contribution = Contribution::get(FALSE)
          ->addSelect('*', 'contribution_status_id:name', 'financial_type_id:name', 'payment_instrument_id:name', 'contribution_recur_id.*', 'Gift_Data.*', 'contribution_extra.*', 'Stock_Information.*', 'Gift_Information.*')
          ->addWhere('contribution_extra.gateway', '=', $message['gateway'])
          ->addWhere('contribution_extra.gateway_txn_id', '=', $message['gateway_txn_id'])
          ->execute()->first();
        if ($contribution) {
          $this->ids['Contribution'][] = $contribution['id'];
          $this->ids['Contact'][] = $contribution['contact_id'];
        }
      }
      catch (\CRM_Core_Exception $e) {
        // do nothing
      }
    }
    if ($queueConsumer === 'Recurring' && $message['txn_type'] === 'subscr_payment') {
      $this->processQueue('donations', 'Donation');
    }
    return $result;
  }

  /**
   * Process the given queue.
   *
   * @param array $message
   * @param string|null $queueConsumer
   *   QueueConsumer if different from property. e.g 'Recurring'
   *   (QueueConsumer is appended in the function.)
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessageWithoutQueuing(array $message, ?string $queueConsumer = NULL): void {
    $queueConsumer = $queueConsumer ?: $this->queueConsumer;
    $queueConsumerClass = '\\Civi\\WMFQueue\\' . $queueConsumer . 'QueueConsumer';
    /* @var = \Civi\WMFQueue\QueueConsumer */
    $consumer = new $queueConsumerClass('test');
    $consumer->processMessage($message);
  }

  /**
   * Temporarily set foreign exchange rates to known values.
   */
  protected function setExchangeRates(int $timestamp, array $rates): void {
    foreach ($rates as $currency => $rate) {
      CRM_ExchangeRates_BAO_ExchangeRate::addToCache(
        $currency, (new \DateTime('@' . $timestamp))->format('YmdHis'), $rate
      );
    }
  }

  /**
   * Get a contribution (from the database) previously registered under `$this->ids[]`.
   *
   * @param string $identifier
   *
   * @return array
   */
  public function getContribution(string $identifier): array {
    try {
      return Contribution::get(FALSE)
        ->addSelect('*', 'contribution_status_id:name', 'financial_type_id:name', 'payment_instrument_id:name', 'contribution_recur_id.*', 'Gift_Data.*', 'contribution_extra.*', 'Stock_Information.*', 'Gift_Information.*')
        ->addWhere('id', '=', $this->ids['Contribution'][$identifier])
        ->execute()->single();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('contribution lookup failed: ' . $e->getMessage());
    }
  }

  /**
   * @param array $donation_message
   *
   * @return array
   */
  public function getContributionForMessage(array $donation_message): array {
    try {
      return Contribution::get(FALSE)
        ->addSelect('*', 'contribution_status_id:name', 'financial_type_id:name', 'payment_instrument_id:name', 'contribution_recur_id.*', 'Gift_Data.*', 'contribution_extra.*', 'Stock_Information.*', 'Gift_Information.*')
        ->addWhere('contribution_extra.gateway', '=', $donation_message['gateway'])
        ->addWhere('contribution_extra.gateway_txn_id', '=', $donation_message['gateway_txn_id'])
        ->execute()->single();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('contribution lookup failed: ' . $e->getMessage());
    }
  }

  /**
   * Assert that the DB contact has a specific value.
   *
   * Note that this will re-use the contact it has looked up if accessed multiple times.
   *
   * If you don't want it to re-use a loaded contact you should set up a different identifier.
   *
   * @param int $id
   *   The key used to identify the contact in the IDs array. You may need to add it to this array
   * @param string $value
   *   The expected value
   * @param string $key
   *   The key to look up.
   */
  public function assertContactValue(int $id, string $value, string $key): void {
    try {
      $contact = Contact::get(FALSE)
        ->addWhere('id', '=', $id)
        ->addSelect($key)
        ->execute()->single();
      $this->assertEquals($value, $contact[$key]);
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to loop up value');
    }
  }

}

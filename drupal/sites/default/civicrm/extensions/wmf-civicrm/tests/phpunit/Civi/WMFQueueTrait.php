<?php

namespace Civi;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\WMFQueue;
use SmashPig\Core\DataStores\QueueWrapper;

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
   * @param array $values
   *
   * @return array
   */
  protected function processDonationMessage(array $values = []): array {
    $donation_message = $this->getDonationMessage($values);
    $this->processMessage($donation_message, 'Donation', 'test');
    return $donation_message;
  }

  /**
   * @param array $values
   *   Any values to be used instead of the loaded ones.
   * @param array $exchangeRates
   *   Exchange rates to set, defaults to setting USD to 1
   *   and the loaded currency to 3.
   *
   * @return array
   */
  public function getDonationMessage(array $values = [], array $exchangeRates = ['USD' => 1, 'PLN' => 0.5]): array {
    $message = $this->getBasicDonationMessage();
    $message['gateway_txn_id'] = mt_rand();
    $contributionTrackingID = mt_rand();
    $message += [
      'order_id' => "$contributionTrackingID.1",
      'contribution_tracking_id' => $contributionTrackingID,
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
      'anonymous' => '1',
      'city_2' => 'cc',
      'city' => 'cc',
      'comment' => '',
      'contribution_tracking_id' => '441',
      'country_2' => 'DE',
      'country' => 'IL',
      'currency' => 'PLN',
      'date' => 1234567,
      'email' => 'test+201@local.net',
      'fee' => '0',
      'first_name_2' => 'b',
      'first_name' => 'Mickey',
      'gateway_account' => 'default',
      'gateway' => 'globalcollect',
      'gateway_txn_id' => '3611204184',
      'gross' => '952.34',
      'language' => 'en',
      'last_name_2' => 'w',
      'last_name' => 'Mouse',
      'middle_name' => '',
      'optout' => '1',
      'original_currency' => 'PLN',
      'original_gross' => '952.34',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'postal_code' => '11122',
      'postal_code_2' => '11122',
      'premium_language' => 'en',
      'response' => 'Original Response Status (pre-SET_PAYMENT) => 600',
      'size' => '',
      'state_province_2' => 'AL',
      'state_province' => 'Haifa',
      'street_address_2' => 'st 2nd.',
      'street_address' => 'ss',
      'supplemental_address_1' => '',
      'supplemental_address_2' => '',
      'user_ip' => '127.0.0.2',
      'utm_campaign' => '',
      'utm_medium' => '',
      'utm_source' => '..cc'
    ];
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
    return $this->processQueue($queueName, $queueConsumer);
  }

  /**
   * Temporarily set foreign exchange rates to known values.
   */
  protected function setExchangeRates(int $timestamp, array $rates): void {
    foreach ($rates as $currency => $rate) {
      exchange_rate_cache_set($currency, $timestamp, $rate);
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
        ->addSelect('*', 'contribution_status_id:name', 'contribution_recur_id.*', 'Gift_Data.*')
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
   * @param string $identifier
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

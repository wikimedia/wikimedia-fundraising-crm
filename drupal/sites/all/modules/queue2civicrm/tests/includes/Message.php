<?php

class Message {

  protected $defaults = [];

  public $body;

  protected $data;

  function __construct($values = []) {
    $this->data = $this->defaults;
    $this->set($values);
  }

  function set($values) {
    if (is_array($values)) {
      $this->data = $values + $this->data;
    }

    $this->body = json_encode($this->data);
  }

  function unset($key) {
    unset($this->data[$key]);
    $this->body = json_encode($this->data);
  }

  function getBody() {
    return $this->data;
  }

  function loadDefaults($name) {
    if (!$this->defaults) {
      $path = __DIR__ . "/../data/{$name}.json";
      $this->defaults = json_decode(file_get_contents($path), TRUE);
    }
  }

  /**
   * Generates random data for queue and donation insertion testing
   */
  public static function generateRandom() {
    //language codes
    $lang = ['en', 'de', 'fr'];

    $currency_codes = ['USD', 'GBP', 'EUR', 'ILS'];
    shuffle($currency_codes);
    $currency = (mt_rand(0, 1)) ? 'USD' : $currency_codes[0];

    $message = [
      'contribution_tracking_id' => '',
      'comment' => mt_rand(),
      'utm_source' => mt_rand(),
      'utm_medium' => mt_rand(),
      'utm_campaign' => mt_rand(),
      'language' => $lang[array_rand($lang)],
      'email' => mt_rand() . '@example.com',
      'first_name' => mt_rand(),
      'middle_name' => mt_rand(),
      'last_name' => mt_rand(),
      'street_address' => mt_rand(),
      'supplemental_address_1' => '',
      'city' => 'San Francisco',
      'state_province' => 'CA',
      'country' => 'USA',
      'countryID' => 'US',
      'postal_code' => mt_rand(2801, 99999),
      'gateway' => 'insert_test',
      'gateway_txn_id' => mt_rand(),
      'response' => mt_rand(),
      'currency' => $currency,
      'original_currency' => $currency_codes[0],
      'original_gross' => mt_rand(0, 10000) / 100,
      'fee' => '0',
      'gross' => mt_rand(0, 10000) / 100,
      'net' => mt_rand(0, 10000) / 100,
      'date' => date('r'), //time(),
    ];
    return $message;
  }

}

class TransactionMessage extends Message {

  protected $txn_id_key = 'gateway_txn_id';

  function __construct($values = []) {
    $this->loadDefaults("donation");
    $ct_id = mt_rand();

    parent::__construct($values + [
        $this->txn_id_key => mt_rand(),
        'order_id' => "$ct_id.1",
        'contribution_tracking_id' => $ct_id,
      ]);
  }

  function getGateway() {
    return $this->data['gateway'];
  }

  function getGatewayTxnId() {
    return $this->data[$this->txn_id_key];
  }

  function get($key) {
    return $this->data[$key];
  }

}

class RecurringSignupMessage extends TransactionMessage {

  function __construct($values = []) {
    $this->loadDefaults("recurring_signup");

    parent::__construct($values);
  }

}

/**
 * Class AmazonDonationMessage Sparse message format pointing to donor
 *  details in the pending database
 */
class AmazonDonationMessage extends TransactionMessage {

  function __construct($values = []) {
    $this->loadDefaults("sparse_donation_amazon");

    parent::__construct($values);
    $this->data['completion_message_id'] =
      'amazon-' . $this->get('order_id');
  }

}

/**
 * Class DlocalDonationMessage Sparse message format pointing to donor
 *  details in the pending database
 */
class DlocalDonationMessage extends TransactionMessage {

  function __construct($values = []) {
    $this->loadDefaults("sparse_donation_dlocal");

    parent::__construct($values);
    $this->data['completion_message_id'] =
      'dlocal-' . $this->get('order_id');
  }

}

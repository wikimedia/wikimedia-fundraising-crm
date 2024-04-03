<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Contribution;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFQueue\RecurDeadlockQueueConsumer;
use Civi\WMFQueue\RecurringQueueConsumer;
use SmashPig\Core\DataStores\DamagedDatabase;

/**
 * @group Queue2Civicrm
 * @group Recurring
 */
class RecurringQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  use \Civi\Test\ContactTestTrait;

  /**
   * @var RecurringQueueConsumer
   */
  protected $consumer;

  /**
   * @var DamagedDatabase
   */
  protected $damagedDb;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new RecurringQueueConsumer(
      'recurring'
    );

    $this->damagedDb = DamagedDatabase::get();

    // Set up for TestMailer
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
  }

  protected function importMessage(TransactionMessage $message) {
    $payment_time = $message->get('date');
    exchange_rate_cache_set('USD', $payment_time, 1);
    $currency = $message->get('currency');
    if ($currency !== 'USD') {
      exchange_rate_cache_set($currency, $payment_time, 3);
    }
    $this->consumer->processMessage($message->getBody());
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    if (!empty($contributions[0])) {
      $this->addToCleanup($contributions[0]);
    }
    return $contributions;
  }

  /**
   * @param array $overrides
   *
   * @return array
   */
  public function processRecurringPaymentMessage(array $overrides): array {
    $message = new RecurringPaymentMessage($overrides);
    $this->importMessage($message);
    return $message->getBody();
  }

  /**
   * @param array $donation_message
   *
   * @return array
   */
  public function getContributionForMessage(array $donation_message): array {
    try {
      return Contribution::get(FALSE)
        ->addSelect('*', 'contribution_status_id:name', 'contribution_recur_id.*')
        ->addWhere('contribution_extra.gateway', '=', $donation_message['gateway'])
        ->addWhere('contribution_extra.gateway_txn_id', '=', $donation_message['gateway_txn_id'])
        ->execute()->single();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('contribution lookup failed: ' . $e->getMessage());
    }
  }

  /**
   * Test function that expires recurrings.
   */
  public function testExpireContributions(): void {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $this->importMessage(new RecurringEOTMessage($values));

    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->ids['Contact'][] = $recur_record['contact_id'];
    $this->assertEquals('(auto) Expiration notification', $recur_record['cancel_reason']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($recur_record['end_date'])));
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertTrue(empty($recur_record['failure_retry_date']));
    $this->assertTrue(empty($recur_record['next_sched_contribution_date']));
    $this->assertEquals('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
  }

  /**
   *  Test that a token is created for a new ingenico recurring donation and a recurring contribution
   *  is created correctly
   */
  public function testRecurringTokenIngenico(): void {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
    ]);

    $message = new TransactionMessage([
        'gateway' => 'ingenico',
        'gross' => 400,
        'original_gross' => 400,
        'original_currency' => 'USD',
        'contribution_tracking_id' => $ct_id,
      ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Set up token specific values
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'], $overrides['recurring_payment_token']);
    // Check the token was created successfully
    $this->assertEquals($token['token'], $overrides['recurring_payment_token']);

    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $trxn_id]);
    // Check the record was created successfully
    $this->assertEquals($recur_record['trxn_id'], $trxn_id);
    // The first contribution should be on the start_date
    $this->assertEquals($recur_record['next_sched_contribution_date'], $recur_record['start_date']);

    // Check cycle_day matches the start date
    $this->assertEquals($recur_record['cycle_day'], date('j', $overrides['start_date']));

    // Clean up
    $this->ids['ContributionRecur'][$recur_record['id']] = $recur_record['id'];
    $this->ids['Contact'][$recur_record['contact_id']] = $recur_record['contact_id'];
  }

  /**
   * Test that a recurring donation created after a one-time donation with the
   * same ct_id is assigned to the same donor
   */
  public function testRecurringSignupAfterOneTime() {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
    ]);

    $message = new TransactionMessage([
        'gateway' => 'ingenico',
        'gross' => 400,
        'original_gross' => 400,
        'original_currency' => 'USD',
        'contribution_tracking_id' => $ct_id,
      ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Set up token specific values
    $overrides['currency'] = 'USD';
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'], $overrides['recurring_payment_token']);
    // Check that the token belongs to the same donor as the first donation
    $this->assertEquals($firstContribution['contact_id'], $token['contact_id']);
    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recurRecord = RecurHelper::getByGatewaySubscriptionId($overrides['gateway'], $trxn_id);
    // Check that the recur record belongs to the same donor
    $this->assertEquals($firstContribution['contact_id'], $recurRecord['contact_id']);

    // Clean up
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
  }

  /**
   * Test that the notification email is sent when a updonate recurring subscription is started
   */
  public function testRecurringNotificationEmailSend(): void {
    \Civi::settings()->set('thank_you_add_civimail_records', FALSE);

    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
    ]);

    $message = new TransactionMessage([
        'gateway' => 'ingenico',
        'gross' => 400,
        'original_gross' => 400,
        'original_currency' => 'CAD',
        'contribution_tracking_id' => $ct_id,
      ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    exchange_rate_cache_set('CAD', $messageBody['date'], 2);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Set up token specific values
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['currency'] = 'CAD';
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    $this->assertEquals(1, $this->getMailingCount());
    $sent = $this->getMailing(0);

    // Check the right email
    $this->assertEquals($messageBody['email'], $sent['to_address']);

    // Check right email content
    $this->assertMatchesRegularExpression('/you donated, and then decided to set up an additional/', $sent['html']);

    // Check the right donation amount
    $this->assertMatchesRegularExpression('/3.00/', $sent['html']);

    // Check the right donation currency, original currency is CAD
    $this->assertMatchesRegularExpression('/CA\$/', $sent['html']);
    // Check the subject.
    // Note this test will move to an extension, at which point this relative path will change.
    $expectedSubject = trim(file_get_contents(
      __DIR__ .
      '/../../../../../default/civicrm/extensions/wmf-civicrm/msg_templates/monthly_convert/monthly_convert.en.subject.txt'
    ));
    $this->assertEquals($expectedSubject, $sent['subject']);
  }

  /**
   * @param array $values
   *
   * @return array
   */
  public function getRecurringPaymentMessage(array $values = []): array {
    $values += ['txn_type' => 'subscr_payment'];
    return (new RecurringPaymentMessage($values))->getBody();
  }

  /**
   * Process the original recurring sign up message.
   *
   * @param string|array $subscr_id
   * @param array $overrides
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  private function processRecurringSignup($subscr_id, $overrides = []) {
    if (is_array($subscr_id)) {
      // This is a transitional hack because the class we are copying to just
      // has one array parameter.
      $overrides = $subscr_id;
    }
    $values = $overrides + ['subscr_id' => $subscr_id];
    $signup_message = new RecurringSignupMessage($values);
    $subscr_time = $signup_message->get('date');
    exchange_rate_cache_set('USD', $subscr_time, 1);
    exchange_rate_cache_set($signup_message->get('currency'), $subscr_time, 2);
    $this->consumer->processMessage($signup_message->getBody());
    return $values;
  }

  private function addToCleanup($contribution) {
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    if (!empty($contribution['contribution_recur_id'])) {
      $this->ids['ContributionRecur'][$contribution['contribution_recur_id']] = $contribution['contribution_recur_id'];
    }
  }

  /**
   * @param array $values
   *
   * @return array|mixed
   */
  public function getRecurringEOTMessage(array $values) {
    $message = new RecurringEOTMessage($values);
    $message = $message->getBody();
    return $message;
  }

}

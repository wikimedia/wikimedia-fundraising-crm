<?php

use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFQueue\RecurringQueueConsumer;

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

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new RecurringQueueConsumer(
      'recurring'
    );

    // Set up for TestMailer
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
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

}

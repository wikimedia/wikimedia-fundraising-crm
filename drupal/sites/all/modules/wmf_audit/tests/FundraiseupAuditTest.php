<?php

use SmashPig\Core\Context;
use SmashPig\PaymentProviders\Fundraiseup\Tests\FundraiseupTestConfiguration;
use queue2civicrm\DonationQueueConsumer;
use queue2civicrm\refund\RefundQueueConsumer;
use queue2civicrm\recurring\RecurringQueueConsumer;

/**
 * @group Fundraiseup
 * @group WmfAudit
 */
class FundraiseupAuditTest extends BaseAuditTestCase {

  public function setUp(): void {
    parent::setUp();
    $ctx = Context::get();
    $config = FundraiseupTestConfiguration::instance($ctx->getGlobalConfiguration());
    $ctx->setProviderConfiguration($config);

    $dirs = [
      'fundraiseup_audit_recon_completed_dir' => $this->getTempDir(),
      'fundraiseup_audit_working_log_dir' => $this->getTempDir(),
    ];

    foreach ($dirs as $var => $dir) {
      if (!is_dir($dir)) {
        mkdir($dir);
      }
      variable_set($var, $dir);
    }
  }

  public function auditTestProvider() {
    return [
    [
      __DIR__ . '/data/Fundraiseup/donations/',
                        [
                          'donations' => [
                        [
                          'gateway' => 'fundraiseup',
                          'gross' => '5.64',
                          'currency' => 'USD',
                          'order_id' => 'DQZQFCJS',
                          'gateway_txn_id' => 'ch_3NrmZLJaRQOHTfEW0zGlJw1Z',
                          'payment_method' => 'cc',
                          'payment_submethod' => 'visa',
                          'date' => 1695063200,
                          'user_ip' => '127.0.0.1',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'street_address' => '',
                          'city' => '',
                          'country' => '',
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SUBJJCQA',
                          'invoice_id' => 'DQZQFCJS',
                          'gateway_account' => 'Wikimedia Foundation',
                          'frequency_unit' => 'month',
                          'frequency_interval' => 1,
                          'original_currency' => 'GBP',
                          'original_gross' => '4.60',
                          'fee' => 0.61,
                          'recurring' => '1',
                          'subscr_id' => 'RCGCEFBA',
                          'start_date' => '2023-09-18T18:53:20.676Z',
                          'employer' => '',
                          'street_number' => '',
                          'postal_code' => '',
                          'state_province' => '',
                          'language' => 'en-US',
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'no_thank_you' => 'Fundraiseup import',
                          'type' => 'donations',
                        ],
                        [
                          'gateway' => 'fundraiseup',
                          'gross' => '12.26',
                          'currency' => 'USD',
                          'order_id' => 'DVSCKVLS',
                          'gateway_txn_id' => 'ch_3NrmYWJaRQOHTfEW0IQMgfTB',
                          'payment_method' => 'google',
                          'payment_submethod' => 'visa',
                          'date' => 1695063150,
                          'user_ip' => '127.0.0.1',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'street_address' => '',
                          'city' => '',
                          'country' => '',
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SUBJJCQA',
                          'invoice_id' => 'DVSCKVLS',
                          'gateway_account' => 'Wikimedia Foundation',
                          'frequency_unit' => 'month',
                          'frequency_interval' => 1,
                          'original_currency' => 'GBP',
                          'original_gross' => '10.00',
                          'fee' => 0.97,
                          'recurring' => '1',
                          'subscr_id' => 'REZZBWQF',
                          'start_date' => '2023-09-18T18:52:30.273Z',
                          'employer' => '',
                          'street_number' => '',
                          'postal_code' => '',
                          'state_province' => '',
                          'language' => 'en-US',
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'no_thank_you' => 'Fundraiseup import',
                          'type' => 'donations',
                        ],
                        [
                          'gateway' => 'fundraiseup',
                          'gross' => '13.49',
                          'currency' => 'USD',
                          'order_id' => 'DGVYEEWH',
                          'gateway_txn_id' => 'ch_3NrmWyJaRQOHTfEW1KdRmJIX',
                          'payment_method' => 'bt',
                          'payment_submethod' => 'ACH',
                          'date' => 1695063056,
                          'user_ip' => '127.0.0.1',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'street_address' => '',
                          'city' => '',
                          'country' => '',
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SCHNECUN',
                          'invoice_id' => 'DGVYEEWH',
                          'gateway_account' => 'Wikimedia Foundation',
                          'frequency_unit' => 'One time',
                          'original_currency' => 'GBP',
                          'original_gross' => '11.00',
                          'fee' => 1.03,
                          'recurring' => '0',
                          'subscr_id' => '',
                          'start_date' => '',
                          'employer' => '',
                          'street_number' => '',
                          'postal_code' => '',
                          'state_province' => '',
                          'language' => 'en-US',
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'no_thank_you' => 'Fundraiseup import',
                          'type' => 'donations',
                        ],
                        [
                          'gateway' => 'fundraiseup',
                          'gross' => '65.91',
                          'currency' => 'USD',
                          'order_id' => 'DXLKVGQU',
                          'gateway_txn_id' => 'ch_3NrfJTJaRQOHTfEW0mf8ewoL',
                          'payment_method' => 'cc',
                          'payment_submethod' => 'visa',
                          'date' => 1695035313,
                          'user_ip' => '127.0.0.1',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'street_address' => '',
                          'city' => '',
                          'country' => '',
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SUBJJCQA',
                          'invoice_id' => 'DXLKVGQU',
                          'gateway_account' => 'Wikimedia Foundation',
                          'frequency_unit' => 'One time',
                          'original_currency' => 'GBP',
                          'original_gross' => '53.70',
                          'fee' => 3.87,
                          'recurring' => '0',
                          'subscr_id' => '',
                          'start_date' => '',
                          'employer' => '',
                          'street_number' => '',
                          'postal_code' => '',
                          'state_province' => '',
                          'language' => 'en-US',
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'no_thank_you' => 'Fundraiseup import',
                          'type' => 'donations',
                        ],
                          ],
                        ],
    ],
                [
                  __DIR__ . '/data/Fundraiseup/refunds',
                [
                  'refund' => [
                        [
                          'gateway' => 'fundraiseup',
                          'gross' => '53.70',
                          'gross_currency' => 'GBP',
                          'gateway_parent_id' => 'ch_3NrfJTJaRQOHTfEW0mf8ewoL',
                          'gateway_refund_id' => 'ch_3NrfJTJaRQOHTfEW0mf8ewoL',
                          'type' => 'refund',
                          'date' => 1695047409,
                        ],
                  ],
                ],
                ],
    [
      __DIR__ . '/data/Fundraiseup/recurring/cancelled',
                [
                  'recurring' => [
                        [
                          'gateway' => 'fundraiseup',
                          'gateway_account' => 'Wikimedia Foundation',
                          'subscr_id' => 'RWRYRXYC',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'employer' => '',
                          'gross' => '4.60',
                          'currency' => 'GBP',
                          'cancel_date' => 1695140630,
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SUBJJCQA',
                          'payment_method' => 'cc',
                          'payment_submethod' => 'visa',
                          'next_sched_contribution_date' => '',
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'type' => 'recurring',
                          'txn_type' => 'subscr_cancel',
                          'start_date' => 1695063200,
                          'frequency_unit' => 'month',
                          'date' => 1695063200,
                          'create_date' => 1695063200,
                          'frequency_interval' => 1,
                          'no_thank_you' => 'Fundraiseup import',
                        ],
                  ],
                ],
    ],
    [
      __DIR__ . '/data/Fundraiseup/recurring/new',
                [
                  'recurring' => [
                        [
                          'gateway' => 'fundraiseup',
                          'gateway_account' => 'Wikimedia Foundation',
                          'subscr_id' => 'RWRYRXYC',
                          'first_name' => 'Jimmy',
                          'last_name' => 'Wales',
                          'employer' => '',
                          'gross' => '10.00',
                          'currency' => 'GBP',
                          'frequency_unit' => 'month',
                          'frequency_interval' => 1,
                          'start_date' => 1695035319,
                          'create_date' => 1695035319,
                          'email' => 'jwales@example.org',
                          'external_identifier' => 'SUBJJCQA',
                          'payment_method' => 'cc',
                          'payment_submethod' => 'visa',
                          'next_sched_contribution_date' => 1697627269,
                          'utm_medium' => 'spontaneous',
                          'utm_source' => 'fr-redir',
                          'utm_campaign' => 'spontaneous',
                          'type' => 'recurring',
                          'date' => 1695035319,
                          'txn_type' => 'subscr_signup',
                          'no_thank_you' => 'Fundraiseup import',
                        ],
                  ],
                ],
    ],
    ];
  }

  /**
   * @dataProvider auditTestProvider
   */
  public function testParseFiles($path, $expectedMessages) {
    variable_set('fundraiseup_audit_recon_files_dir', $path);

    $this->runAuditor();

    $this->assertMessages($expectedMessages);
  }

  public function testImportCreditCardUsdDonationMessages() {
    $audit = $this->auditTestProvider()[0];
    $donation = $audit[1]['donations'][0];
    $dqc = new DonationQueueConsumer('test');
    $message = new TransactionMessage($donation);
    $dqc->processMessage($message->getBody());
    $this->consumeCtQueue();

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => 'USD',
      'total_amount' => 5.64,
      'fee_amount' => 0.61,
      'net_amount' => 5.03,
      'trxn_id' => 'RECURRING FUNDRAISEUP ch_3NrmZLJaRQOHTfEW0zGlJw1Z',
      'source' => 'GBP 4.60',
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Completed"),
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', "Credit Card: Visa"),
    );

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('*', 'contact_id.*',)
      ->addWhere('invoice_id', 'LIKE', $donation['invoice_id'] . "%")
      ->execute()->first();

    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('custom.*',)
      ->addWhere('id', '=', $contribution['contact_id'])
      ->execute()->first();

    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];

    $this->assertEquals($contact["External_Identifiers.fundraiseup_id"], $donation['external_identifier']);
    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key]);
    }

  }

  public function testImportGooglePayDonationMessages() {
    $audit = $this->auditTestProvider()[0];
    $donation = $audit[1]['donations'][1];
    $dqc = new DonationQueueConsumer('test');
    $message = new TransactionMessage($donation);
    $dqc->processMessage($message->getBody());
    $this->consumeCtQueue();

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => 'USD',
      'total_amount' => 12.26,
      'fee_amount' => 0.97,
      'net_amount' => 11.29,
      'trxn_id' => 'RECURRING FUNDRAISEUP ch_3NrmYWJaRQOHTfEW0IQMgfTB',
      'source' => 'GBP 10.00',
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Completed"),
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', "Google Pay: Visa"),
    );

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('*', 'contact_id.*',)
      ->addWhere('invoice_id', 'LIKE', $donation['invoice_id'] . "%")
      ->execute()->first();

    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('custom.*',)
      ->addWhere('id', '=', $contribution['contact_id'])
      ->execute()->first();

    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];

    $this->assertEquals($contact["External_Identifiers.fundraiseup_id"], $donation['external_identifier']);
    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key]);
    }
  }

  public function testImportBtDonationMessages() {
    $audit = $this->auditTestProvider()[0];
    $donation = $audit[1]['donations'][2];
    $dqc = new DonationQueueConsumer('test');
    $message = new TransactionMessage($donation);
    $dqc->processMessage($message->getBody());
    $this->consumeCtQueue();

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => 'USD',
      'total_amount' => 13.49,
      'fee_amount' => 1.03,
      'net_amount' => 12.46,
      'trxn_id' => 'FUNDRAISEUP ch_3NrmWyJaRQOHTfEW1KdRmJIX',
      'source' => 'GBP 11.00',
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Cash"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Completed"),
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', "Bank Transfer: ACH"),
    );

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('*', 'contact_id.*',)
      ->addWhere('invoice_id', 'LIKE', $donation['invoice_id'] . "%")
      ->execute()->first();

    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('custom.*',)
      ->addWhere('id', '=', $contribution['contact_id'])
      ->execute()->first();

    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];

    $this->assertEquals($contact["External_Identifiers.fundraiseup_id"], $donation['external_identifier']);
    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key]);
    }
  }

  public function testImportRefundDonationMessages() {
    $audit = $this->auditTestProvider();
    $donation = $audit[0][1]['donations'][3];
    $refund = $audit[1][1]['refund'][0];
    $dqc = new DonationQueueConsumer('test');
    $rfqc = new RefundQueueConsumer(
    'refund'
    );
    $message = new TransactionMessage($donation);
    $dqc->processMessage($message->getBody());
    $this->consumeCtQueue();

    $refundMessage = new TransactionMessage($refund);
    $rfqc->processMessage($refundMessage->getBody());

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => 'USD',
      'total_amount' => 65.91,
      'fee_amount' => 3.87,
      'net_amount' => 62.04,
      'trxn_id' => 'FUNDRAISEUP ch_3NrfJTJaRQOHTfEW0mf8ewoL',
      'source' => 'GBP 53.70',
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Cash"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Refunded"),
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', "Credit Card: Visa"),
    );

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('*', 'contact_id.*',)
      ->addWhere('invoice_id', 'LIKE', $donation['invoice_id'] . "%")
      ->execute()->first();

    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];

    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $contribution[$key]);
    }
  }

  public function testImportNewRecurring() {
    $audit = $this->auditTestProvider();
    $recurring = $audit[3][1]['recurring'][0];
    $rqc = new RecurringQueueConsumer(
    'recurring'
    );
    $rqc->processMessage($recurring);

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => $recurring['currency'],
      'amount' => $recurring['gross'],
      'trxn_id' => $recurring['subscr_id'],
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Cash"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Pending"),
    );

    $recurRow = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('*', 'contact_id.*')
      ->addWhere('trxn_id', '=', $recurring['subscr_id'])
      ->execute()->first();

    $this->ids['Contact'][$recurRow['contact_id']] = $recurRow['contact_id'];
    $this->ids['ContributionRecur'][$recurRow['id']] = $recurRow['id'];

    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $recurRow[$key]);
    }
  }

  public function testImportCancelRecurring() {
    $audit = $this->auditTestProvider();
    $newRecurringMsg = $audit[3][1]['recurring'][0];
    $cancelMsg = $audit[2][1]['recurring'][0];
    $rqc = new RecurringQueueConsumer(
    'recurring'
    );
    $rqc->processMessage($newRecurringMsg);
    $rqc->processMessage($cancelMsg);

    $expected = array(
      'contact_id.contact_type' => 'Individual',
      'contact_id.sort_name' => 'Wales, Jimmy',
      'contact_id.display_name' => 'Jimmy Wales',
      'contact_id.first_name' => 'Jimmy',
      'contact_id.last_name' => 'Wales',
      'currency' => $newRecurringMsg['currency'],
      'amount' => $newRecurringMsg['gross'],
      'trxn_id' => $newRecurringMsg['subscr_id'],
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Cash"),
      'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', "Cancelled"),
    );

    $recurRow = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('*', 'contact_id.*')
      ->addWhere('trxn_id', '=', $newRecurringMsg['subscr_id'])
      ->execute()->first();

    $this->ids['Contact'][$recurRow['contact_id']] = $recurRow['contact_id'];
    $this->ids['ContributionRecur'][$recurRow['id']] = $recurRow['id'];

    foreach ($expected as $key => $item) {
      $this->assertEquals($item, $recurRow[$key]);
    }
  }

  protected function runAuditor() {
    $options = [
      'fakedb' => TRUE,
      'quiet' => TRUE,
      'test' => TRUE,
    #'verbose' => 'true', # Uncomment to debug.
    ];
    $audit = new FundraiseupAuditProcessor($options);
    $audit->run();
  }

}
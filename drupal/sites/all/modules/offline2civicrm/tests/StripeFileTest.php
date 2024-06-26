<?php

use Civi\Api4\Contact;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class StripeTest extends BaseChecksFileTest {

  protected $gateway = 'stripe';

  /**
   * Test basic import.
   */
  function testImport() {
    civicrm_initialize();

    $importer = new StripeFile(__DIR__ . "/data/stripe.csv");
    $messages = $importer->import();

    $this->assertEquals('2 out of 3 rows were imported.', $messages['Result']);
    $firstGateWayID = 'ch_1Al1231231231231231231123';
    $contribution = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $firstGateWayID);
    $this->assertEquals(1, count($contribution));
    $this->assertEquals('STRIPE ch_1Al1231231231231231231123', $contribution[0]['trxn_id']);
    $this->assertEquals(500, $contribution[0]['total_amount']);
    $this->assertEquals('USD', $contribution[0]['currency']);
    // This line never truly worked - ie do a fresh build on master
    // & run it in isolation & it fails. It also fails on jenkins if
    // we do proper cleanup. The issue appears to be that the
    // contribution_tracking_id generated in the test config always
    // 'generates' id 1  - so it overwrites the first row with the
    // campaign with the next, without it.
    // $this->assertEquals('big campaign', db_query("SELECT {utm_campaign} from {contribution_tracking} WHERE contribution_id = {$contribution[0]['id']}")->fetchField());

    $contact = $this->callAPISuccessGetSingle('Contact', [
      'id' => $contribution[0]['contact_id'],
      'return' => [
        'first_name',
        'last_name',
        'contact_source',
      ],
    ]);
    $this->assertEquals('Charles', $contact['first_name']);
    $this->assertEquals('Darwin', $contact['last_name']);
    $this->assertEquals('Stripe import', $contact['contact_source']);

    $this->ids['contribution'][2] = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, 'ch_1Bl1231231231231231231123')[0];
    $this->assertEquals(1000, $this->ids['contribution'][2]['original_amount']);
    $this->assertEquals('GBP', $this->ids['contribution'][2]['original_currency']);
    $this->assertEquals('USD', $this->ids['contribution'][2]['currency']);
    $this->assertEquals(1500, $this->ids['contribution'][2]['total_amount']);
    $this->assertEquals('GBP 1000.00', $this->ids['contribution'][2]['source']);
  }

}

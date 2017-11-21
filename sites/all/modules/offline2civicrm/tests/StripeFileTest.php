<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class StripeTest extends BaseChecksFileTest {

  function setUp() {
    parent::setUp();
    $this->gateway = 'stripe';
  }

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
    $this->assertEquals('STRIPE CH_1AL1231231231231231231123', $contribution[0]['trxn_id']);
    $this->assertEquals('500', $contribution[0]['total_amount']);
    $this->assertEquals('USD', $contribution[0]['currency']);
    $this->assertEquals('big campaign', db_query("SELECT {utm_campaign} from {contribution_tracking} WHERE contribution_id = {$contribution[0]['id']}")->fetchField());

    $contact = $this->callAPISuccessGetSingle('Contact', array('id' => $contribution[0]['contact_id'], 'return' => array(
      'first_name',
      'last_name',
      'contact_source',
    )));
    $this->assertEquals('Charles', $contact['first_name']);
    $this->assertEquals('Darwin', $contact['last_name']);
    $this->assertEquals('Stripe import', $contact['contact_source']);

    $contribution2 = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, 'ch_1Bl1231231231231231231123');
    $contribution2 = $contribution2[0];
    $this->assertEquals(1000, $contribution2['original_amount']);
    $this->assertEquals('GBP', $contribution2['original_currency']);
    $this->assertEquals('USD', $contribution2['currency']);
    $this->assertEquals(1500, $contribution2['total_amount']);
    $this->assertEquals('GBP 1000', $contribution2['source']);

    $this->callAPISuccess('Contribution', 'delete', array('id' => $contribution[0]['id']));
    $this->callAPISuccess('Contribution', 'delete', array('id' => $contribution[0]['id'] + 1));
    $this->callAPISuccess('Contact', 'delete', array('id' => $contact['id']));
    $this->callAPISuccess('Contact', 'delete', array('id' => $contact['id'] + 1));
  }

}

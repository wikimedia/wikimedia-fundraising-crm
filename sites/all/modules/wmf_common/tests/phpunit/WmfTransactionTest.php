<?php

/**
 * @group WmfCommon
 */
class WmfTransactionTestCase extends BaseWmfDrupalPhpUnitTestCase {
    public function testParseUniqueId() {
        $transaction = WmfTransaction::from_unique_id( "RFD RECURRING GLOBALCOLLECT 1234 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "5-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            true, $transaction->is_refund,
            "refund flag parsed" );
        $this->assertEquals(
            true, $transaction->is_recurring,
            "recurring flag parsed" );
        $this->assertEquals(
            "globalcollect", strtolower( $transaction->gateway ),
            "gateway is correctly parsed" );
        $this->assertEquals(
            "432", $transaction->timestamp,
            "timestamp is correctly parsed" );
        $this->assertEquals(
            $transaction->get_unique_id(), "RFD RECURRING GLOBALCOLLECT 1234",
            "5-argument form is renormalized to 4-form" );

        $transaction = WmfTransaction::from_unique_id( "RFD GLOBALCOLLECT 1234 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "4-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            true, $transaction->is_refund,
            "refund flag parsed" );
        $this->assertEquals(
            "432", $transaction->timestamp,
            "timestamp is correctly parsed" );
        $this->assertEquals(
            $transaction->get_unique_id(), "RFD GLOBALCOLLECT 1234",
            "4-argument form is renormalized correctly" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234x 432" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234x",
            "3-argument form gateway_txn_id is parsed correctly." );
        $this->assertEquals(
            $transaction->get_unique_id(), strtoupper( "GLOBALCOLLECT 1234x" ),
            "3-argument form is renormalized correctly" );

        $transaction = WmfTransaction::from_unique_id( "GLOBALCOLLECT 1234" );
        $this->assertEquals(
            $transaction->gateway_txn_id, "1234",
            "2-argument form gateway_txn_id is parsed correctly." );
        $this->assertNull( $transaction->timestamp,
            "timestamp is not unnecessarily invented" );
    }

    public function testParseMessage() {
        $msg = array(
            'gateway' => "globalcollect",
            'gateway_txn_id' => "1234",
            'recurring' => null,
        );
        $transaction = WmfTransaction::from_message( $msg );
        $this->assertEquals(
            "1234", $transaction->gateway_txn_id,
            "parsed message gateway_txn_id is correct" );
        $this->assertEquals( 1,
            preg_match( "/GLOBALCOLLECT 1234/", $transaction->get_unique_id() ),
            "parsed message has correct trxn_id" );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    function testInvalidEmptyId() {
        $transaction = WmfTransaction::from_unique_id( "" );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    function testInvalidAlmostEmptyId() {
        $transaction = WmfTransaction::from_unique_id( 'RFD RECURRING' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    function testInvalidWhitespaceId() {
        $transaction = WmfTransaction::from_unique_id( 'RFD RECURRING ' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    function testInvalidExtraPartsId() {
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY 123 1234 EXTRA_PART' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_MESSAGE
     */
    function testInvalidTimestampId() {
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY 123 BAD_TIMESTAMP' );
    }

    function testExistsNone() {
        civicrm_initialize();
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . mt_rand() );
        $this->assertEquals( false, $transaction->exists() );
    }

    function testExistsOne() {
        $gateway_txn_id = mt_rand();
        $msg = array(
            'gross' => 1,
            'currency' => 'USD',
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
            'payment_method' => 'cc',
            'email' => 'nobody@wikimedia.org',
        );
        wmf_civicrm_contribution_message_import( $msg );
        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . $gateway_txn_id );
        $this->assertEquals( true, $transaction->exists() );
    }

    /**
     * @expectedException NonUniqueTransaction
     */
    function testGetContributionMany() {
        $gateway_txn_id = mt_rand();
        $contact = $this->callAPISuccess('Contact', 'create', array(
            'contact_type' => 'Individual',
            'display_name' => 'test',
        ));
        $params = array(
            'contact_id' => $contact['id'],
            'contribution_type' => 'Cash',
            'total_amount' => 1,
            'version' => 3,
        );
        $contribution = $this->callAPISuccess('Contribution', 'create', $params);
        wmf_civicrm_set_custom_field_values($contribution['id'], array(
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
        ) );
        $contribution = $this->callAPISuccess('Contribution', 'create', $params);
        wmf_civicrm_set_custom_field_values($contribution['id'], array(
            'gateway' => 'TEST_GATEWAY',
            'gateway_txn_id' => $gateway_txn_id,
        ) );

        $transaction = WmfTransaction::from_unique_id( 'TEST_GATEWAY ' . $gateway_txn_id );
        $transaction->getContribution();
    }

  /**
   * Test that when an exception is thrown without our wrapper no further rollback happens.
   *
   * (this is really just the 'control' for the following test.
   */
    public function testNoRollBack() {
      civicrm_initialize();
      CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");

      $this->callbackFunction(1);

      $this->assertEquals('Cool planet', CRM_Core_DAO::singleValueQuery('SELECT description FROM civicrm_domain LIMIT 1'));
      $contact = $this->callAPISuccess('Contact', 'get', array('external_identifier' => 'oh so strange'));
      $this->assertEquals(1, $contact['count']);

      // Cleanup
      $this->callAPISuccess('Contact', 'delete', array('id' => $contact['id']));
      CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");
    }

  /**
   * Test that when an exception is thrown with our wrapper the whole lot rolls back.
   */
  public function testFullRollBack() {
    civicrm_initialize();
    CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");

    try {
      WmfDatabase::transactionalCall(array($this, 'callbackFunction'), array());
    }
    catch (RuntimeException $e) {
      // We were expecting this :-)
    }

    $this->assertEquals('WMF', CRM_Core_DAO::singleValueQuery('SELECT description FROM civicrm_domain LIMIT 1'));
    $contact = $this->callAPISuccess('Contact', 'getcount', array('external_identifier' => 'oh so strange'));
    $this->assertEquals(0, $contact['count']);
  }

    public function callbackFunction() {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'Cool planet'");
      $contact = array(
        'contact_type' => 'Individual',
        'first_name' => 'Dr',
        'last_name' => 'Strange',
        'external_identifier' => 'oh so strange',
      );
      $this->createTestContact($contact);
      try {
        civicrm_api3('Contact', 'create', $contact);
      }
      catch (Exception $e) {
        // We have done nothing to roll back.
        return;
      }
    }

}

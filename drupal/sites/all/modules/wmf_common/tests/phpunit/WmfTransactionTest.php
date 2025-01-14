<?php

use Civi\WMFException\WMFException;
use Civi\WMFException\NonUniqueTransaction;
use Civi\WMFTransaction;

/**
 * @group WmfCommon
 */
class WmfTransactionTestCase extends BaseWmfDrupalPhpUnitTestCase {

  public function testParseUniqueId() {
    $transaction = WMFTransaction::from_unique_id("RFD RECURRING GLOBALCOLLECT 1234 432");
    $this->assertEquals(
      $transaction->gateway_txn_id, "1234",
      "5-argument form gateway_txn_id is parsed correctly.");
    $this->assertEquals(
      TRUE, $transaction->is_refund,
      "refund flag parsed");
    $this->assertEquals(
      TRUE, $transaction->is_recurring,
      "recurring flag parsed");
    $this->assertEquals(
      "globalcollect", strtolower($transaction->gateway),
      "gateway is correctly parsed");
    $this->assertEquals(
      "432", $transaction->timestamp,
      "timestamp is correctly parsed");
    $this->assertEquals(
      $transaction->get_unique_id(), "RFD RECURRING GLOBALCOLLECT 1234",
      "5-argument form is renormalized to 4-form");

    $transaction = WMFTransaction::from_unique_id("RFD GLOBALCOLLECT 1234 432");
    $this->assertEquals(
      $transaction->gateway_txn_id, "1234",
      "4-argument form gateway_txn_id is parsed correctly.");
    $this->assertEquals(
      TRUE, $transaction->is_refund,
      "refund flag parsed");
    $this->assertEquals(
      "432", $transaction->timestamp,
      "timestamp is correctly parsed");
    $this->assertEquals(
      $transaction->get_unique_id(), "RFD GLOBALCOLLECT 1234",
      "4-argument form is renormalized correctly");

    $transaction = WMFTransaction::from_unique_id("GLOBALCOLLECT 1234x 432");
    $this->assertEquals(
      $transaction->gateway_txn_id, "1234x",
      "3-argument form gateway_txn_id is parsed correctly.");
    $this->assertEquals(
      $transaction->get_unique_id(),"GLOBALCOLLECT 1234x",
      "3-argument form is renormalized correctly");

    $transaction = WMFTransaction::from_unique_id("GLOBALCOLLECT 1234");
    $this->assertEquals(
      $transaction->gateway_txn_id, "1234",
      "2-argument form gateway_txn_id is parsed correctly.");
    $this->assertNull($transaction->timestamp,
      "timestamp is not unnecessarily invented");
  }

  public function testParseMessage() {
    $msg = [
      'gateway' => "globalcollect",
      'gateway_txn_id' => "1234",
      'recurring' => NULL,
    ];
    $transaction = WMFTransaction::from_message($msg);
    $this->assertEquals(
      "1234", $transaction->gateway_txn_id,
      "parsed message gateway_txn_id is correct");
    $this->assertEquals(1,
      preg_match("/GLOBALCOLLECT 1234/", $transaction->get_unique_id()),
      "parsed message has correct trxn_id");
  }

  function testInvalidEmptyId() {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::INVALID_MESSAGE);
    WMFTransaction::from_unique_id("");
  }

  function testInvalidAlmostEmptyId() {
    $this->expectExceptionCode(WMFException::INVALID_MESSAGE);
    $this->expectException(WMFException::class);
    WMFTransaction::from_unique_id('RFD RECURRING');
  }

  public function testInvalidWhitespaceId(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::INVALID_MESSAGE);
    WMFTransaction::from_unique_id('RFD RECURRING ');
  }

  public function testInvalidExtraPartsId(): void {
    $this->expectExceptionCode(WMFException::INVALID_MESSAGE);
    $this->expectException(WMFException::class);
    WMFTransaction::from_unique_id('TEST_GATEWAY 123 1234 EXTRA_PART');
  }

  public function testInvalidTimestampId(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionCode(WMFException::INVALID_MESSAGE);
    WMFTransaction::from_unique_id('TEST_GATEWAY 123 BAD_TIMESTAMP');
  }

  /**
   * Test that when an exception is thrown without our wrapper no further
   * rollback happens.
   *
   * (this is really just the 'control' for the following test.
   */
  public function testNoRollBack() {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");

    $this->callbackFunction(1);

    $this->assertEquals('Cool planet', CRM_Core_DAO::singleValueQuery('SELECT description FROM civicrm_domain LIMIT 1'));
    $contact = $this->callAPISuccess('Contact', 'get', ['external_identifier' => 'oh so strange']);
    $this->assertEquals(1, $contact['count']);

    // Cleanup
    $this->callAPISuccess('Contact', 'delete', ['id' => $contact['id']]);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");
  }

  /**
   * Test that when an exception is thrown with our wrapper the whole lot rolls
   * back.
   */
  public function testFullRollBack() {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'WMF'");

    try {
      \Civi\WMFHelper\Database::transactionalCall([$this, 'callbackFunction'], []);
    }
    catch (RuntimeException $e) {
      // We were expecting this :-)
      unset($this->ids['Contact']);
    }

    $this->assertEquals('WMF', CRM_Core_DAO::singleValueQuery('SELECT description FROM civicrm_domain LIMIT 1'));
    $count = $this->callAPISuccess('Contact', 'getcount', ['external_identifier' => 'oh so strange']);
    $this->assertEquals(0, $count);
  }

  public function callbackFunction() {
    CRM_Core_DAO::executeQuery("UPDATE civicrm_domain SET description = 'Cool planet'");
    $contact = [
      'contact_type' => 'Individual',
      'first_name' => 'Dr',
      'last_name' => 'Strange',
      'external_identifier' => 'oh so strange',
    ];
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

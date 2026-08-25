<?php

namespace Civi\Api4\Action\Base62;

use Civi\Api4\Base62;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group Base62
 */
class Base62Test extends TestCase {
  use WMFEnvironmentTrait;

  // Known-good UUID <-> Base62 pair, taken from SmashPig's own Base62Helper test fixtures.
  private const TRANSACTION_ID = '3f9c958c-ee57-4121-a79e-408946b27077';
  private const RECONCILIATION_ID = '1w24hGOdCSFLtsgBQr2jKh';

  public function setUp(): void {
    $this->setUpWMFEnvironment();
    parent::setUp();
  }

  public function tearDown(): void {
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  public function testConvertReconciliationIdToTransactionId(): void {
    $result = Base62::convert(FALSE)
      ->setReconciliationId(self::RECONCILIATION_ID)
      ->execute()->single();
    $this->assertEquals(self::RECONCILIATION_ID, $result['reconciliation_id']);
    $this->assertEquals(self::TRANSACTION_ID, $result['transaction_id']);
  }

  public function testConvertTransactionIdToReconciliationId(): void {
    $result = Base62::convert(FALSE)
      ->setTransactionId(self::TRANSACTION_ID)
      ->execute()->single();
    $this->assertEquals(self::RECONCILIATION_ID, $result['reconciliation_id']);
    $this->assertEquals(self::TRANSACTION_ID, $result['transaction_id']);
  }

  public function testConvertThrowsWhenNeitherIdProvided(): void {
    $this->expectException(\CRM_Core_Exception::class);
    Base62::convert(FALSE)->execute();
  }

  public function testValidatePassesForMatchingPair(): void {
    $result = Base62::validate(FALSE)
      ->setReconciliationId(self::RECONCILIATION_ID)
      ->setTransactionId(self::TRANSACTION_ID)
      ->execute()->single();
    $this->assertTrue($result['is_valid']);
  }

  public function testValidateFailsForMismatchedPair(): void {
    $result = Base62::validate(FALSE)
      ->setReconciliationId(self::RECONCILIATION_ID)
      ->setTransactionId('00000000-0000-4000-8000-000000000000')
      ->execute()->single();
    $this->assertFalse($result['is_valid']);
  }

  public function testValidateThrowsWhenTransactionIdMissing(): void {
    $this->expectException(\CRM_Core_Exception::class);
    Base62::validate(FALSE)
      ->setReconciliationId(self::RECONCILIATION_ID)
      ->execute();
  }

  public function testValidateThrowsWhenReconciliationIdMissing(): void {
    $this->expectException(\CRM_Core_Exception::class);
    Base62::validate(FALSE)
      ->setTransactionId(self::TRANSACTION_ID)
      ->execute();
  }

}

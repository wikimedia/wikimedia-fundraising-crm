<?php

namespace Civi\WMFBatch;

use PHPUnit\Framework\TestCase;

class BatchDiscrepancyExplainerTest extends TestCase {

  /**
   * One extra $2 donation (fee 0.45) in CiviCRM - from a real paypal batch.
   */
  public function testOneExtraCiviTransaction(): void {
    $validation = ['count' => -1, 'credit' => -2, 'debit' => 0, 'fee' => 0.44999999999982, 'settled' => -1.5499999999993];
    $this->assertEquals(['count' => 1, 'gross' => 2.0, 'refunds' => 0.0, 'fee' => 0.45, 'net' => 1.55], BatchDiscrepancyExplainer::getCiviMinusExpected($validation));
    $explanation = BatchDiscrepancyExplainer::explain($validation, 'USD');
    $this->assertStringStartsWith('CiviCRM has 1 more transaction, USD 2.00 more donations, USD 0.45 more fees, USD 1.55 more net than the batch expects.', $explanation);
    $this->assertStringContainsString('single whole transaction in CiviCRM that is not in the settlement file', $explanation);
  }

  public function testMissingFromCivi(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 2, 'credit' => 30, 'debit' => 0, 'fee' => -1, 'settled' => 29], 'GBP');
    $this->assertStringStartsWith('CiviCRM has 2 fewer transactions, GBP 30.00 less donations, GBP 1.00 less fees, GBP 29.00 less net', $explanation);
    $this->assertStringContainsString('not settled to this batch in CiviCRM', $explanation);
  }

  public function testFeeSplitOnly(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => -0.3, 'debit' => 0, 'fee' => 0.3, 'settled' => 0], 'EUR');
    $this->assertStringContainsString('the cash is right but the GL split is not', $explanation);
  }

  public function testInconsistent(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => 0, 'debit' => 0, 'fee' => 0, 'settled' => 5], 'USD');
    $this->assertStringContainsString('These do not add up', $explanation);
  }

  public function testValid(): void {
    $this->assertSame('', BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => 0, 'debit' => 0, 'fee' => 0, 'settled' => 0], 'USD'));
  }

}

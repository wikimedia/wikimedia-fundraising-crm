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
    $this->assertEquals('CiviCRM has 1 more transaction than the batch expects (donations +USD 2.00, fees +USD 0.45, net +USD 1.55).', $explanation);
  }

  public function testMissingFromCivi(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 2, 'credit' => 30, 'debit' => 0, 'fee' => -1, 'settled' => 29], 'GBP');
    $this->assertEquals('CiviCRM has 2 fewer transactions than the batch expects (donations -GBP 30.00, fees -GBP 1.00, net -GBP 29.00).', $explanation);
  }

  public function testFeeSplitOnly(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => -0.3, 'debit' => 0, 'fee' => 0.3, 'settled' => 0], 'EUR');
    $this->assertStringContainsString('Net matches - only the donation/fee split differs.', $explanation);
  }

  public function testInconsistent(): void {
    $explanation = BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => 0, 'debit' => 0, 'fee' => 0, 'settled' => 5], 'USD');
    $this->assertStringContainsString('Donations - refunds - fees != net', $explanation);
  }

  public function testValid(): void {
    $this->assertSame('', BatchDiscrepancyExplainer::explain(['count' => 0, 'credit' => 0, 'debit' => 0, 'fee' => 0, 'settled' => 0], 'USD'));
  }

}

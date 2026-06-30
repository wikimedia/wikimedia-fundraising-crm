<?php

namespace phpunit\Civi\WMFAudit;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\MatchingGift;
use Civi\WMFAudit\BaseAuditTestCase;

/**
 * @group Trustly
 * @group WmfAudit
 */
class ChariotAuditTest extends BaseAuditTestCase {

  protected string $gateway = 'chariot';

  protected array $batches = [
    'groundswell' => [
      'name' => 'chariot_01kqkvxnj5mc751be13egn6j6p_USD',
     ],
    'pinkaloo' => [
      'name' => 'chariot_01krysqhrtsdzva44bbaf37r4h_USD',
      'file' => '20260519123012-Ren_Pinkaloo_-50000.00-deposit_01krysqhrtsdzva44bbaf37r4h.csv',
    ],
    'fidelity' => [
        'name' => 'chariot_01ks0ckx633sqdjrmwews9cs49_USD',
        'file' => '20260520123012-Fidelity_Charitable-1696000.00-deposit_01ks0ckx633sqdjrmwews9cs49.csv',
      ],
    'benevity' => [
      'name' => 'chariot_01kt1vy6h4tez9jh2z51hvvpb0_USD',
      'file' => '20260602123036-Benevity-30497.00-deposit_01kt1vy6h4tez9jh2z51hvvpb0.csv',
    ],
  ];

  /**
   * Nest audit files to parse in the incoming directory layout.
   *
   * @var bool
   */
  protected bool $useIncomingDirectory = FALSE;

  public function tearDown(): void {
    Contribution::delete(FALSE)
      ->addWhere('contribution_settlement.settlement_batch_reference', 'IN', $this->getBatchNames())
      ->execute();
    Batch::delete(FALSE)
      ->addWhere('name', 'IN', $this->getBatchNames())
      ->execute();
    $this->cleanupContact(['organization_name' => 'Existing Duplicate Org']);
    parent::tearDown();
  }

  public function testGroundswellMatchingGiftFile(): void {
    $this->runAuditBatch('', '20260502081216-Groundswell-1000.00-deposit_01kqkvxnj5mc751be13egn6j6p.csv');
    $contributions = Contribution::get(FALSE)
      ->setSelect(['*', 'payment_instrument_id:name', 'contribution_extra.*', 'Gift_Data.Channel:label'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'chariot_01kqkvxnj5mc751be13egn6j6p_USD')
      ->addOrderBy('id', 'ASC')
      ->execute();
    $this->assertCount(2, $contributions);
    $firstContribution = $contributions->first();
    $this->assertEquals('EFT', $firstContribution['payment_instrument_id:name']);
    $this->assertEquals('Workplace Giving', $firstContribution['Gift_Data.Channel:label']);
  }

  public function testFidelityFullNameHandling(): void {
    $this->runAuditBatch('', '20260520123012-Fidelity_Charitable-1696000.00-deposit_01ks0ckx633sqdjrmwews9cs49.csv');
    $contributions = Contribution::get(FALSE)
      ->setSelect(['*', 'contact_id.display_name', 'payment_instrument_id:name'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'chariot_01ks0ckx633sqdjrmwews9cs49_USD')
      ->addOrderBy('id', 'ASC')
      ->execute();
    $this->assertCount(2, $contributions);
    $contribution = $contributions->first();
    $this->assertEquals('The Firm', $contribution['contact_id.display_name']);
    $this->assertEquals('ACH', $contribution['payment_instrument_id:name']);
    $contributionSoft = ContributionSoft::get(FALSE)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->addWhere('soft_credit_type_id:name', '=', 'donor-advised_fund')
      ->setSelect(['*', 'contact_id.display_name', 'contact_id.first_name', 'contact_id.last_name'])
      ->execute()->single();
    $this->assertEquals('Marge', $contributionSoft['contact_id.first_name']);
    $this->assertEquals('Mouse', $contributionSoft['contact_id.last_name']);
    $this->assertEquals('Marge Mouse', $contributionSoft['contact_id.display_name']);
  }

  /**
   * Test that when 2 existing organizations look likely we use selection methods
   * like address.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testFidelityOrganizationExistingMatch(): void {
    $this->createOrganization(['organization_name' => 'Existing Duplicate Org']);
    $this->createOrganization(['organization_name' => 'Existing Duplicate Org', 'address_primary.street_address' => '10 Downing St']);
    $this->runAuditBatch('', '20260520123012-Fidelity_Charitable-1696000.00-deposit_01ks0ckx633sqdjrmwews9cs49.csv');
    $contribution = Contribution::get(FALSE)
      ->setSelect(['*', 'contact_id.display_name', 'payment_instrument_id:name'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'chariot_01ks0ckx633sqdjrmwews9cs49_USD')
      ->addWhere('contact_id.display_name', '=', 'Existing Duplicate Org')
      ->execute()->single();

    $contributionSoft = ContributionSoft::get(FALSE)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->addWhere('soft_credit_type_id:name', '=', 'donor-advised_fund')
      ->setSelect(['*', 'contact_id.display_name', 'contact_id.first_name', 'contact_id.last_name'])
      ->execute()->single();
    $this->assertEquals('Lisa', $contributionSoft['contact_id.first_name']);
    $this->assertEquals('Mouse', $contributionSoft['contact_id.last_name']);
    $this->assertEquals('Lisa Mouse', $contributionSoft['contact_id.display_name']);
  }
  public function testPinkalooDAFFile(): void {
    $this->runAuditBatch('', $this->getBatchFile('pinkaloo'));
    $contribution = Contribution::get(FALSE)
      ->setSelect(['*', 'contribution_extra.*', 'payment_instrument_id:name'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', 'chariot_01krysqhrtsdzva44bbaf37r4h_USD')
      ->addOrderBy('id', 'DESC')
      ->execute()->single();
    $this->assertEquals('Check', $contribution['payment_instrument_id:name']);
    $softCredit = ContributionSoft::get(FALSE)
      ->addWhere('contribution_id', '=', $contribution['id'])
      ->addSelect('soft_credit_type_id:name', 'contact_id.display_name', 'amount')
      ->execute()->indexBy('contact_id.display_name');
    $this->assertCount(2, $softCredit);
    $this->assertEquals('donor-advised_fund', $softCredit['Homer Simpson']['soft_credit_type_id:name']);
    $this->assertEquals('Banking Institution', $softCredit['Morgan Stanley GIFT']['soft_credit_type_id:name']);
  }

  public function testBenevityFile(): void {
    // set up some duplicate organizations.
    $this->createTestEntity('Contact', ['organization_name' => 'Mouse'], 'mouse_1');
    $this->createTestEntity('Contact', ['organization_name' => 'Mouse'], 'mouse_2');

    $this->runAuditBatch('', $this->getBatchFile('benevity'));
    $contributions = (array) Contribution::get(FALSE)
      ->setSelect(['*', 'contribution_extra.*', 'payment_instrument_id:name', 'financial_type_id:name', 'Gift_Data.*'])
      ->addWhere('contribution_settlement.settlement_batch_reference', '=', $this->getBatchName('benevity'))
      ->addOrderBy('id')
      ->execute();
    // 3 individual gifts, 2 matching gifts, 1 fee row.
    $this->assertCount(6, $contributions);
    $this->assertEquals('Employee Giving', $contributions[0]['Gift_Data.Campaign']);

    $contributionSoft = ContributionSoft::get(FALSE)
      ->addWhere('contribution_id', 'IN', \CRM_Utils_Array::collect('id', $contributions))
      ->addSelect('*', 'soft_credit_type_id:name')
      ->execute();
    $this->assertCount(3, $contributionSoft);
    $this->assertEquals('workplace', $contributionSoft[0]['soft_credit_type_id:name']);
    // Check it runs again without error.
    $this->runAuditBatch('', $this->getBatchFile('benevity'));
  }

  public function testMatchingGiftSave(): void {
    $fields = MatchingGift::getFields(FALSE)->execute()->indexBy('name');
    $this->assertEquals('String', $fields['banking_institution']['data_type']);
  }

  public function createTransactionLog(array $row): void {}

  /**
   * @param string $directory
   * @param string $fileName
   *
   * @return array
   */
  public function getRows(string $directory, string $fileName): array {
    return [];
  }

  public function getBatchFile($batch): string {
    return $this->batches[$batch]['file'];
  }

  public function getBatchName($batch): string {
    return $this->batches[$batch]['name'];
  }

  public function getBatchNames(): array {
    $names = [];
    foreach ($this->batches as $batch) {
      $names[] = $batch['name'];
    }
    return $names;
  }

}

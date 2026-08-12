<?php

namespace Civi\WMFHook;

use Civi\Api4\Contribution;
use Civi\Api4\RelationshipCache;
use Civi\Test\HeadlessInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use Civi\Test\EntityTrait;

class CustomPreDataTest extends TestCase implements HeadlessInterface {

  use WMFEnvironmentTrait;
  use EntityTrait;

  /**
   * Tests the hook addition of donor advised fund relationship.
   *
   * The hook ensures that editing the donor advised fund custom field
   * leads to the relationship being created, allowing the field
   * to be added to batch data entry and imports.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testDonorAdvisedFundHook(): void {
    $this->createOrganization();
    $this->createIndividual();
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'donor_advised_fund.owns_donor_advised_for' => $this->ids['Contact']['organization'],
      'financial_type_id:name' => 'Donation',
    ]);
    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);

    // Now check what happens if the relationship already exists (hint should be nothing).
    $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'donor_advised_fund.owns_donor_advised_for' => $this->ids['Contact']['organization'],
      'financial_type_id:name' => 'Donation',
    ]);
    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);
  }

  /**
   * Editing an existing contribution to set the donor advised fund custom
   * field, without resubmitting contact_id, should still find the right
   * contact - contributionPre() only has contact_id directly available
   * when it's part of the same save, so createDonorAdvisedRelationshipFromCustomField()
   * needs to fall back to looking it up via the contribution id.
   *
   * @throws \CRM_Core_Exception
   */
  public function testDonorAdvisedFundHookOnEditWithoutContactId(): void {
    $this->createOrganization();
    $this->createIndividual();
    $contribution = $this->createTestEntity('Contribution', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'total_amount' => 50,
      'financial_type_id:name' => 'Donation',
    ]);

    // Edit without contact_id - the fallback lookup by entityID must kick in.
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('donor_advised_fund.owns_donor_advised_for', $this->ids['Contact']['organization'])
      ->execute();

    $relationships = RelationshipCache::get(FALSE)
      ->addWhere('near_contact_id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $this->assertCount(1, $relationships);
  }

}

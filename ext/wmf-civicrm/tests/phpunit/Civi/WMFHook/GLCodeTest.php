<?php

namespace Civi\WMFHook;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

class GLCodeTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public static function glCodeProvider(): array {
    return [
      'restricted fund' => [['Gift_Data.Fund' => 'Restricted - Foundation', 'Gift_Data.Channel' => 'Workplace Giving'], 'GL43428'],
      'Workplace Giving' => [['Gift_Data.Channel' => 'Workplace Giving', 'total_amount' => 10, 'Gift_Data.Appeal' => 'MGGO26'], 'GL43488'],
      'Workplace Giving 10000 major gifts appeal' => [['Gift_Data.Channel' => 'Workplace Giving', 'total_amount' => 10000, 'Gift_Data.Appeal' => 'MGGO26'], 'GL43485'],
      'Chapter Gifts' => [['Gift_Data.Channel' => 'Chapter Gifts', 'total_amount' => 10000, 'Gift_Data.Appeal' => 'MGGO26'], 'GL43440'],
      'Planned Giving 10000' => [['Gift_Data.Channel' => 'Planned Giving', 'total_amount' => 10000], 'GL43485'],
      'Direct Solicitation under 10000' => [['Gift_Data.Channel' => 'Direct Solicitation', 'total_amount' => 10], 'GL43487'],
      '10000 not major gifts' => [['total_amount' => 10000, 'Gift_Data.Channel' => 'Email'], 'GL43482'],
      'DAF 10000' => [['total_amount' => 10000, 'Gift_Data.Campaign' => 'Donor Advised Fund', 'Gift_Data.Channel' => 'Email'], 'GL43485'],
      'DAF 1000' => [['total_amount' => 1000, 'Gift_Data.Campaign' => 'Donor Advised Fund', 'Gift_Data.Channel' => 'Email'], 'GL43486'],
      'DAF under 1000' => [['total_amount' => 999.99, 'Gift_Data.Campaign' => 'Donor Advised Fund', 'Gift_Data.Channel' => 'Email'], 'GL43482'],
      'DAF under 1000 major gifts appeal' => [['total_amount' => 999.99, 'Gift_Data.Campaign' => 'Donor Advised Fund', 'Gift_Data.Appeal' => 'MGGO26', 'Gift_Data.Channel' => 'Email'], 'GL43486'],
      'White Mail 10000' => [['total_amount' => 10000, 'Gift_Data.Appeal' => 'White Mail', 'Gift_Data.Channel' => 'Direct Mail Upload'], 'GL43485'],
      'White Mail appeal suffix' => [['total_amount' => 1000, 'Gift_Data.Appeal' => 'WMF1125FWM', 'Gift_Data.Channel' => 'Direct Mail'], 'GL43489'],
      'White Mail under 1000' => [['total_amount' => 999.99, 'Gift_Data.Appeal' => 'White Mail', 'Gift_Data.Channel' => 'Direct Mail Upload'], 'GL43483'],
      'Other Offline' => [['total_amount' => 10, 'Gift_Data.Channel' => 'Other Offline'], 'GL43484'],
      'Other Offline 1000' => [['total_amount' => 1000, 'Gift_Data.Channel' => 'Other Offline', 'Gift_Data.Appeal' => 'SPRING26'], 'GL43489'],
      'Other Offline MG appeal' => [['total_amount' => 10, 'Gift_Data.Channel' => 'Other Offline', 'Gift_Data.Appeal' => 'MGGO26'], 'GL43489'],
      'MG appeal' => [['Gift_Data.Channel' => 'Email', 'Gift_Data.Appeal' => 'MGGO26'], 'GL43487'],
      'no channel' => [['total_amount' => 10000, 'Gift_Data.Appeal' => 'MGGO26'], NULL],
    ];
  }

  /**
   * Create sets the GL code from the first matching rule.
   *
   * @dataProvider glCodeProvider
   */
  public function testCreateSetsGLCode(array $values, ?string $expected): void {
    $contribution = $this->createContribution($values);
    $this->assertEquals($expected, $this->getGLCode($contribution['id']));
  }

  public static function relationshipManagerProvider(): array {
    return [
      'DAF' => [['total_amount' => 1000, 'Gift_Data.Campaign' => 'Donor Advised Fund', 'Gift_Data.Channel' => 'Email']],
      'Direct Solicitation' => [['total_amount' => 10, 'Gift_Data.Channel' => 'Direct Solicitation']],
    ];
  }

  /**
   * A donor with a relationship manager outranks the DAF and offline channel
   * rules.
   *
   * @dataProvider relationshipManagerProvider
   */
  public function testRelationshipManagerGLCode(array $values): void {
    $contactID = $this->createIndividual(['Prospect.Relationship_Manager' => '1']);
    $contribution = $this->createContribution(['contact_id' => $contactID] + $values);
    $this->assertEquals('GL43485', $this->getGLCode($contribution['id']));
  }

  /**
   * A contact excluded from gift coding has its relationship manager ignored.
   */
  public function testExcludedContactGLCode(): void {
    $contactID = $this->createIndividual(['Prospect.Relationship_Manager' => '1']);
    $this->setSetting('wmf_gift_coding_excluded_contacts', (string) $contactID);
    $contribution = $this->createContribution(['contact_id' => $contactID, 'Gift_Data.Channel' => 'Email']);
    $this->assertEquals('GL43482', $this->getGLCode($contribution['id']));
  }

  /**
   * A DAF soft credit to a contact with a relationship manager sets the major
   * gifts code.
   */
  public function testDAFHolderRelationshipManagerGLCode(): void {
    $dafHolderID = $this->createIndividual(['Prospect.Relationship_Manager' => '1'], 'daf_holder');
    $contribution = $this->createContribution([
      'contact_id' => $this->createOrganization(),
      'total_amount' => 500,
      'Gift_Data.Campaign' => 'Donor Advised Fund',
      'Gift_Data.Channel' => 'Email',
    ]);
    $this->assertEquals('GL43482', $this->getGLCode($contribution['id']));

    ContributionSoft::create(FALSE)->setValues([
      'contribution_id' => $contribution['id'],
      'contact_id' => $dafHolderID,
      'soft_credit_type_id:name' => 'donor-advised_fund',
      'amount' => 500,
    ])->execute();
    $this->assertEquals('GL43485', $this->getGLCode($contribution['id']));
  }

  /**
   * A DAF soft credit to a contact without a relationship manager leaves the code.
   */
  public function testDAFHolderWithoutRelationshipManagerGLCode(): void {
    $contribution = $this->createContribution([
      'contact_id' => $this->createOrganization(),
      'total_amount' => 500,
      'Gift_Data.Campaign' => 'Donor Advised Fund',
      'Gift_Data.Channel' => 'Email',
    ]);
    ContributionSoft::create(FALSE)->setValues([
      'contribution_id' => $contribution['id'],
      'contact_id' => $this->createIndividual([], 'daf_holder'),
      'soft_credit_type_id:name' => 'donor-advised_fund',
      'amount' => 500,
    ])->execute();
    $this->assertEquals('GL43482', $this->getGLCode($contribution['id']));
  }

  public static function segmentProvider(): array {
    return [
      'mid value' => [1000, 1.23, 'now', 'GL43487'],
      'mid value then 1000' => [1000, 1000, 'now', 'GL43487'],
      'grassroots plus' => [50, 1.23, 'now', 'GL43482'],
      'mid value from a later donation' => [1000, 1.23, '-2 months', 'GL43482'],
      'mid value White Mail under 1000' => [1000, 999.99, 'now', 'GL43489', ['Gift_Data.Appeal' => 'White Mail']],
      'mid value Other Offline' => [1000, 999.99, 'now', 'GL43489', ['Gift_Data.Channel' => 'Other Offline']],
    ];
  }

  /**
   * The donor segment just before the donation decides if it is major gifts
   * associated.
   *
   * @dataProvider segmentProvider
   */
  public function testSegmentGLCode(float $otherAmount, float $amount, string $receiveDate, string $expected, array $values = []): void {
    $contactID = $this->createIndividual();
    $this->createContribution(['contact_id' => $contactID, 'total_amount' => $otherAmount, 'receive_date' => '-1 month'], 'other');
    $contribution = $this->createContribution(array_merge([
      'contact_id' => $contactID,
      'total_amount' => $amount,
      'receive_date' => $receiveDate,
      'Gift_Data.Channel' => 'Email',
      'contribution_extra.gateway_txn_id' => 'gl_code_segment',
    ], $values));
    $this->assertEquals($expected, $this->getGLCode($contribution['id']));
  }

  public static function recurringProvider(): array {
    return [
      'monthly 84' => ['month', 84, 'GL43487'],
      'annual 1000' => ['year', 1000, 'GL43487'],
      'annual 10000' => ['year', 10000, 'GL43485'],
      'annual under 1000' => ['year', 999.99, 'GL43480'],
      'monthly under 84 relationship manager' => ['month', 83.99, 'GL43485', [], ['Prospect.Relationship_Manager' => '1']],
      'monthly sign-up' => ['month', 84, 'GL43482', ['Gift_Data.Channel' => 'Email']],
      'monthly sign-up major gifts associated' => ['month', 10, 'GL43487', ['Gift_Data.Channel' => 'Email', 'Gift_Data.Appeal' => 'MGGO26']],
    ];
  }

  /**
   * Subsequent recurring payments only get a major gifts code over the
   * threshold for their frequency. Sign-ups are coded as one-time gifts.
   *
   * @dataProvider recurringProvider
   */
  public function testRecurringGLCode(string $frequencyUnit, float $amount, string $expected, array $values = [], array $contactValues = []): void {
    $contactID = $this->createIndividual($contactValues);
    $recur = $this->createTestEntity('ContributionRecur', [
      'contact_id' => $contactID,
      'amount' => $amount,
      'frequency_unit' => $frequencyUnit,
    ]);
    $contribution = $this->createContribution(array_merge([
      'contact_id' => $contactID,
      'contribution_recur_id' => $recur['id'],
      'total_amount' => $amount,
      'Gift_Data.Channel' => 'Recurring Gift',
    ], $values));
    $this->assertEquals($expected, $this->getGLCode($contribution['id']));
  }

  /**
   * Changing the appeal recalculates the GL code.
   */
  public function testEditRecalculatesGLCode(): void {
    $contribution = $this->createContribution(['Gift_Data.Channel' => 'Email']);
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('Gift_Data.Appeal', 'MGGO26')
      ->execute();
    $this->assertEquals('GL43487', $this->getGLCode($contribution['id']));
  }

  /**
   * Resubmitting unchanged input fields doesn't recalculate the GL code.
   */
  public function testEditWithUnchangedInputsKeepsGLCode(): void {
    $contribution = $this->createContribution(['Gift_Data.Channel' => 'Email']);
    Contact::update(FALSE)
      ->addWhere('id', '=', $contribution['contact_id'])
      ->addValue('Prospect.Relationship_Manager', '1')
      ->execute();
    $stored = Contribution::get(FALSE)
      ->addSelect('contact_id', 'total_amount', 'receive_date')
      ->addWhere('id', '=', $contribution['id'])
      ->execute()->single();
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->setValues($stored + ['source' => 'Updated'])
      ->execute();
    // No change to any field, so added relationship manager doesn't change code.
    $this->assertEquals('GL43482', $this->getGLCode($contribution['id']));
  }

  private function getGLCode(int $contributionID): ?string {
    return Contribution::get(FALSE)
      ->addSelect('Gift_Data.gl_code')
      ->addWhere('id', '=', $contributionID)
      ->execute()->single()['Gift_Data.gl_code'];
  }

}

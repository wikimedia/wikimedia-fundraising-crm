<?php

namespace Civi\WMFHelper;

use Civi\Api4\Address;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Relationship;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class ContactTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private int $anonymousContactID;
  private int $organizationID;

  public function setUpHeadless(): Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    try {
      $this->anonymousContactID = Contact::getAnonymousContactID();
    }
    catch (\CRM_Core_Exception $e) {
      $this->anonymousContactID = \Civi\Api4\Contact::create(FALSE)
        ->setValues([
          'contact_type' => 'Individual',
          'first_name' => 'Anonymous',
          'last_name' => 'Anonymous',
          'email_primary.email' => 'fakeemail@wikimedia.org',
        ])->execute()->first()['id'];
    }

    $this->organizationID = \Civi\Api4\Contact::create(FALSE)
      ->setValues([
        'contact_type' => 'Organization',
        'organization_name' => 'Test Corp',
      ])->execute()->first()['id'];
  }

  public function testReturnsAnonymousContactWhenAllNull(): void {
    $this->assertSame($this->anonymousContactID,
      Contact::getIndividualID(NULL, NULL, NULL, NULL, NULL));
  }

  public function testReturnsAnonymousContactWhenNamesAreLiterallyAnonymous(): void {
    $this->assertSame($this->anonymousContactID,
      Contact::getIndividualID(NULL, 'Anonymous', 'Anonymous', NULL, NULL));
  }

  public function testReturnsFalseWhenNoContactMatches(): void {
    $this->assertFalse(Contact::getIndividualID('nobody@example.org', 'Ghost', 'Nobody', NULL, NULL));
  }

  public function testReturnsSingleMatchWithEmail(): void {
    $contactID = $this->createIndividual('jane@example.org');
    $this->assertSame($contactID, Contact::getIndividualID('jane@example.org', 'Danger', 'Mouse', NULL, NULL));
  }

  /**
   * A contact that matches by name only (no primary email) is not returned.
   */
  public function testReturnsFalseWhenMatchingContactHasNoEmail(): void {
    $this->createIndividual(NULL);
    $this->assertFalse(Contact::getIndividualID(NULL, 'Danger', 'Mouse', NULL, NULL));
  }

  /**
   * When two contacts share the same name and email, the one employed by the organization is returned.
   */
  public function testOrganizationResolvedFromName(): void {
    $employedID = $this->createIndividual('danger@example.org', $this->organizationID);
    $this->createIndividual('danger@example.org');
    $this->assertSame($employedID, Contact::getIndividualID('danger@example.org', 'Danger', 'Mouse', NULL, 'Test Corp'));
  }

  public function testSoftCredit(): void {
    $this->createIndividual('danger@example.org');
    $softCreditedID = $this->createIndividual('danger@example.org');
    $this->createSoftCredit($softCreditedID, '-60 months');
    $this->assertSame($softCreditedID, Contact::getIndividualID('danger@example.org', 'Danger', 'Mouse', NULL, NULL, $this->organizationID));
  }

  public function testDafRelationship(): void {
    $this->createIndividual('danger@example.org');
    $dafID = $this->createIndividual('danger@example.org');
    $this->createDAFRelationship($dafID);
    $this->assertSame($dafID, Contact::getIndividualID('danger@example.org', 'Danger', 'Mouse', NULL, NULL, $this->organizationID));
  }

  public function testPostalCodeMatch(): void {
    $this->createIndividual('danger@example.org');
    $withPostalID = $this->createIndividual('danger@example.org', NULL, '12345-6789');
    $this->assertSame($withPostalID, Contact::getIndividualID('danger@example.org', 'Danger', 'Mouse', '12345', NULL));
  }

  public function testScoringEmployerPlusSoftCreditOrPostalCode(): void {
    $lookup = fn() => Contact::getIndividualID('danger@example.org', 'Danger', 'Mouse', '12345', NULL, $this->organizationID);

    // Only contact A exists (email only 10).
    $contactA = $this->createIndividual('danger@example.org');
    $this->assertSame($contactA, $lookup(), 'A alone should be returned (score 10)');

    // Add contact B: no employer or DAF but a recent soft credit (10 + 10).
    $contactB = $this->createIndividual('danger@example.org');
    $this->createSoftCredit($contactB, '-3 months');
    $this->assertSame($contactB, $lookup(), 'B (score 20) should beat A (score 10)');

    // Add contact C: employed (10 + 25)
    $contactC = $this->createIndividual('danger@example.org', $this->organizationID);
    $this->assertSame($contactC, $lookup(), 'C (score 35) should beat B (score 20)');

    // Add contact D: employed + matching postal (10 + 25 + 10).
    $contactD = $this->createIndividual('danger@example.org', $this->organizationID, '12345-6789');
    $this->assertSame($contactD, $lookup(), 'D (score 45) should beat C (score 30)');
  }

  /**
   * @param string|null $email
   * @param int|null $employerID
   * @param string|null $postalCode
   *
   * @return int The created contact ID.
   */
  private function createIndividual(?string $email, ?int $employerID = NULL, ?string $postalCode = NULL): int {
    $values = [
      'contact_type' => 'Individual',
      'first_name' => 'Danger',
      'last_name' => 'Mouse',
    ];
    if ($email !== NULL) {
      $values['email_primary.email'] = $email;
    }
    if ($employerID !== NULL) {
      $values['employer_id'] = $employerID;
    }
    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->setValues($values)
      ->execute()->first()['id'];

    if ($postalCode !== NULL) {
      Address::create(FALSE)->setValues([
        'contact_id' => $contactID,
        'postal_code' => $postalCode,
      ])->execute();
    }

    return $contactID;
  }

  /**
   * @param int $contactID
   * @param string $dateOffset
   */
  private function createSoftCredit(int $contactID, string $dateOffset): void {
    $receiveDate = (new \DateTime($dateOffset))->format('Y-m-d');

    $contributionID = Contribution::create(FALSE)->setValues([
      'contact_id' => $this->organizationID,
      'financial_type_id:name' => 'Donation',
      'total_amount' => 100,
      'receive_date' => $receiveDate,
    ])->execute()->first()['id'];

    ContributionSoft::create(FALSE)->setValues([
      'contact_id' => $contactID,
      'contribution_id' => $contributionID,
      'amount' => 100,
      'soft_credit_type_id:name' => 'matched_gift',
    ])->execute();
  }

  /**
   * @param int $contactID
   */
  private function createDAFRelationship(int $contactID): void {
    Relationship::create(FALSE)->setValues([
      'contact_id_a' => $this->organizationID,
      'contact_id_b' => $contactID,
      'relationship_type_id:name' => 'Holds a Donor Advised Fund of',
    ])->execute();
  }

}

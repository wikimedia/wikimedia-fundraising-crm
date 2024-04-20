<?php

use Civi\WMFException\WMFException;

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RelationshipTest extends BaseWmfDrupalPhpUnitTestCase {

  public function testRelationship(): void {
    $msg = $this->processDonationMessage([
      'relationship_target_contact_id' => $this->createIndividual(),
      'relationship_type' => 'Spouse of',
    ]);
    $contribution = $this->getContributionForMessage($msg);
    $relationshipType = $this->callAPISuccessGetSingle('RelationshipType', [
      'name_a_b' => 'Spouse of',
    ]);

    $relationship = $this->callAPISuccessGetSingle('Relationship', [
      'contact_id_a' => $contribution['contact_id'],
    ]);

    $this->assertEquals($relationshipType['id'], $relationship['relationship_type_id']);
  }

  public function testBadRelationshipTarget(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'somebody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'relationship_target_contact_id' => mt_rand(),
      'relationship_type' => 'Spouse of',
    ];

    $this->processMessageWithoutQueuing($msg, 'Donation');
  }

  public function testBadRelationshipType(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'relationship_target_contact_id' => $this->createIndividual(),
      'relationship_type' => 'Total stranger to',
    ];

    $this->processMessageWithoutQueuing($msg, 'Donation', 'test');
  }

}

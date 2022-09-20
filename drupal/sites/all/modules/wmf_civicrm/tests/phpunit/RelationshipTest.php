<?php

use Civi\WMFException\WMFException;

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RelationshipTest extends BaseWmfDrupalPhpUnitTestCase {

  public function testRelationship() {
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',

      'relationship_target_contact_id' => $this->createIndividual(),
      'relationship_type' => 'Spouse of',
    ];

    $contribution = $this->messageImport($msg);

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
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',

      'relationship_target_contact_id' => mt_rand(),
      'relationship_type' => 'Spouse of',
    ];

    $this->messageImport($msg);
  }

  public function testBadRelationshipType(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'currency' => 'USD',
      'date' => time(),
      'email' => 'nobody@wikimedia.org',
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'relationship_target_contact_id' => $this->createIndividual(),
      'relationship_type' => 'Total stranger to',
    ];

    $this->messageImport($msg);
  }

}

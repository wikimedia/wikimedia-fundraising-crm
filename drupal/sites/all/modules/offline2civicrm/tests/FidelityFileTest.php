<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\WMFException\WMFException;

/**
 * @group Import
 * @group Offline2Civicrm
 */
class FidelityFileTest extends BaseChecksFileTest {

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    Contribution::delete(FALSE)->addWhere('trxn_id', 'LIKE', 'Fidelity%')->execute();
    Contact::delete(FALSE)
      ->addWhere('id', '>', $this->maxContactID)
      ->setUseTrash(FALSE)
      ->execute();
    parent::tearDown();
  }

  /**
   * Test basic import.
   *
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   * @throws WMFException
   */
  public function testImport(): void {
    $importer = new FidelityFile($this->getCsvDirectory() . 'fidelity.csv');
    $messages = $importer->import();
    $this->assertEquals('All rows were imported', $messages['Result']);
    $contributions = Contribution::get(FALSE)->addWhere('trxn_id', 'LIKE', 'Fidelity%')
      ->setSelect(['contact_id.first_name', 'contact_id.last_name', 'contact_id.Partner.Partner', 'total_amount',
        'contact_id.prefix_id:label', 'contact_id.organization_name', 'contact_id.addressee_display', 'contact_id.addressee_custom',
      ])
      ->addOrderBy('id')
      ->execute();
    $contribution = $contributions[0];
    $this->assertEquals('50', $contribution['total_amount']);
    $this->assertEquals('Anonymous', $contribution['contact_id.first_name']);
    $this->assertEquals('Anonymous', $contribution['contact_id.last_name']);

    $contribution = $contributions[1];
    $this->assertEquals('Patrick', $contribution['contact_id.first_name']);
    $this->assertEquals('Mouse', $contribution['contact_id.last_name']);
    $this->assertEquals('Mr.', $contribution['contact_id.prefix_id:label']);

    $contribution = $contributions[2];
    $this->assertEquals('John', $contribution['contact_id.first_name']);
    $this->assertEquals('Duck', $contribution['contact_id.last_name']);
    $this->assertEquals('Sally Mouse', $contribution['contact_id.Partner.Partner']);
    $this->assertEquals('John Duck and Sally Mouse', $contribution['contact_id.addressee_display']);
    $this->assertEquals('John Duck and Sally Mouse', $contribution['contact_id.addressee_custom']);

    $contribution = $contributions[3];
    $this->assertEquals('Great Family Foundation', $contribution['contact_id.organization_name']);

    $contribution = $contributions[4];
    $this->assertEquals('Jim', $contribution['contact_id.first_name']);
    $this->assertEquals('Mouse', $contribution['contact_id.last_name']);

    $softCredits = ContributionSoft::get(FALSE)
      ->addWhere('contact_id.display_name', '=', 'Fidelity Charitable Gift Fund')
      ->execute();
    $this->assertCount(5, $softCredits);
  }

}

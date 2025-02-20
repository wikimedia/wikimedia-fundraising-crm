<?php

namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Contact;
use Civi\Api4\WMFDataManagement;
use Civi\Test;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Import tests for WMF DeleteDeletedContact.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test
 * class. Simply create corresponding functions (e.g. "hook_civicrm_post(...)"
 * or similar).
 *
 * @group headless
 */
class DeleteDeletedTest extends TestCase {
  use WMFEnvironmentTrait;
  use Test\EntityTrait;

  /**
   * @throws \CRM_Core_Exception
   */
  public function testDeleteWithContribution(): void {
    $this->createIndividual(['is_deleted' => 1], 'first');
    $this->createTestEntity('Contribution', [
      'total_amount' => 5,
      'contact_id' => $this->createIndividual(['is_deleted' => 1], 'second'),
      'financial_type_id:name' => 'Donation',
    ]);
    $this->createIndividual(['is_deleted' => 1], 'third');
    WMFDataManagement::deleteDeletedContacts(FALSE)
      ->setEndDateTime('+ 1 day')
      ->execute();

    // Check that 1 remains but it did not fall over.
    $contacts = Contact::get(FALSE)
      ->addWhere('id', 'IN', $this->ids['Contact'])
      ->execute();
    $this->assertCount(1, $contacts);
    $this->assertLoggedWarningThatContains('civicrm_cleanup_logs: This contact id is not able to be deleted: ' . $this->ids['Contact']['second']);
  }

}

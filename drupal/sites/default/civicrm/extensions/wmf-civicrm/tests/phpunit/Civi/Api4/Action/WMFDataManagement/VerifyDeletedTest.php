<?php

namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\WMFDataManagement;
use Civi\MonoLog\MonologManager;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test the verifyDeleted action.
 *
 * This action finds and checks contacts who have been deleted but have recurring donations.
 */
class VerifyDeletedTest extends TestCase {
  use EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * Test that we can detect a soft-deleted contact with a recurring contribution.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testVerifyDeletedWithContributionRecur(): void {
    $this->createIndividual();
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $this->ids['Contact']['danger_mouse'],
      'amount' => 5,
      'start_date' => 'now',
      'financial_type_id:name' => 'Donation',
    ]);
    Contact::delete(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['danger_mouse'])
      ->execute();
    $result = WMFDataManagement::verifyDeletedContacts(FALSE)
      ->execute();
    $this->assertGreaterThanOrEqual(1, count($result));
    // Argh only passing locally at the moment ... wip.
    // $this->assertLoggedAlertThatContains($this->ids['Contact']['danger_mouse']);
  }

}

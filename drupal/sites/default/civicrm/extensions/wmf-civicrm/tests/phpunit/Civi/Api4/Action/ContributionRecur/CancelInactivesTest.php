<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use PHPUnit\Framework\TestCase;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 */
class CancelInactivesTest extends TestCase {

  /**
   * The tearDown() method is executed after the test was executed (optional).
   *
   * This can be used for cleanup.
   *
   * @throws \API_Exception
   */
  public function tearDown(): void {
    Contact::delete(FALSE)->addWhere('display_name', '=', 'Walter White')->setUseTrash(FALSE)->execute();
    parent::tearDown();
  }

  /**
   * Test inactive recurring contributions are cancelled.
   */
  public function testCancelInactive(): void {
    $contactID = Contact::create(FALSE)
      ->setValues(['first_name' => 'Walter', 'last_name' => 'White', 'contact_type' => 'Individual'])
      ->execute()->first()['id'];
    $contributionRecurID = ContributionRecur::create(FALSE)
      ->setValues([
        'amount' => 60,
        'contact_id' => $contactID,
        'next_sched_contribution_date' => '70 days ago',
        'contribution_status_id:name' => 'Pending',
      ])->execute()->first()['id'];

    ContributionRecur::cancelInactives(FALSE)->execute();

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contributionRecurID)
      ->addSelect('contribution_status_id:name', 'cancel_reason', 'cancel_date')->execute()->first();

    $this->assertEquals('Automatically cancelled for inactivity', $contributionRecur['cancel_reason']);
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
  }

}

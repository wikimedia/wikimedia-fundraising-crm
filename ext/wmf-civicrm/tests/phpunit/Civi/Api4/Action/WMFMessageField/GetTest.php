<?php

namespace Civi\Api4\Action\WMFMessageField;

use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\WMFMessageField;
use Civi\Test\EntityTrait;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use PHPUnit\Framework\TestCase;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 */
class GetTest extends TestCase {
  use EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * Test use of API4 in Contribution Tracking in recurring module
   *
   * @throws \CRM_Core_Exception
   */
  public function testGetMessageField(): void {
    $fields = WMFMessageField::get(FALSE)->execute();
    $this->assertEquals('gateway', $fields[0]['name']);
  }

}

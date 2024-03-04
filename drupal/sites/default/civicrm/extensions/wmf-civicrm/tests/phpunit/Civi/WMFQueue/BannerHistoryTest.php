<?php

namespace Civi\WMFQueue;

use Civi\WMFException\WMFException;

/**
 * @group Queue2Civicrm
 */
class BannerHistoryTest extends BaseQueue {

  protected string $queueConsumer = 'BannerHistory';

  protected string $queueName = 'test';

  /**
   * Test that no exception is thrown for a valid message.
   */
  public function testValidMessage(): void {
    $this->processMessage([
      'banner_history_id' => substr(
        md5(mt_rand() . time()), 0, 16
      ),
      'contribution_tracking_id' => (string) mt_rand(),
    ]);
    // check for thing in db
    $this->assertEquals(1, 1);
  }

  public function testBadContributionId(): void {
    $this->expectException(WMFException::class);
    $this->processMessageWithoutQueuing([
      'banner_history_id' => substr(
        md5(mt_rand() . time()), 0, 16
      ),
      'contribution_tracking_id' => '1=1; DROP TABLE students;--',
    ]);
  }

  public function testBadHistoryId(): void {
    $this->expectException(WMFException::class);
    $this->processMessageWithoutQueuing([
      'banner_history_id' => '\';GRANT ALL ON drupal.* TO \'hacker\'@\'hack0r\'',
      'contribution_tracking_id' => (string) mt_rand(),
    ]);
  }

}

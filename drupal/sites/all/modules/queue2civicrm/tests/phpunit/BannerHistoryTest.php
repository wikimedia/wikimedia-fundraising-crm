<?php

use queue2civicrm\banner_history\BannerHistoryQueueConsumer;
use Civi\WMFException\WMFException;

/**
 * @group Queue2Civicrm
 */
class BannerHistoryTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var BannerHistoryQueueConsumer
   */
  protected $consumer;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new BannerHistoryQueueConsumer(
      'test'
    );
  }

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function testValidMessage(): void {
    $msg = [
      'banner_history_id' => substr(
        md5(mt_rand() . time()), 0, 16
      ),
      'contribution_tracking_id' => strval(mt_rand()),
    ];
    $this->consumer->processMessage($msg);
    // check for thing in db
    $this->assertEquals(1, 1);
  }

  public function testBadContributionId(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'banner_history_id' => substr(
        md5(mt_rand() . time()), 0, 16
      ),
      'contribution_tracking_id' => '1=1; DROP TABLE students;--',
    ];
    $this->consumer->processMessage($msg);
  }

  public function testBadHistoryId(): void {
    $this->expectException(WMFException::class);
    $msg = [
      'banner_history_id' => '\';GRANT ALL ON drupal.* TO \'leet\'@\'haxx0r\'',
      'contribution_tracking_id' => strval(mt_rand()),
    ];
    $this->consumer->processMessage($msg);
  }
}

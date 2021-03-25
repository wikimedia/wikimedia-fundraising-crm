<?php

use queue2civicrm\banner_history\BannerHistoryQueueConsumer;

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
   * @throws \WmfException
   */
  public function testValidMessage() {
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

  /**
   * @expectedException WmfException
   */
  public function testBadContributionId() {
    $msg = [
      'banner_history_id' => substr(
        md5(mt_rand() . time()), 0, 16
      ),
      'contribution_tracking_id' => '1=1; DROP TABLE students;--',
    ];
    $this->consumer->processMessage($msg);
  }

  /**
   * @expectedException WmfException
   */
  public function testBadHistoryId() {
    $msg = [
      'banner_history_id' => '\';GRANT ALL ON drupal.* TO \'leet\'@\'haxx0r\'',
      'contribution_tracking_id' => strval(mt_rand()),
    ];
    $this->consumer->processMessage($msg);
  }
}

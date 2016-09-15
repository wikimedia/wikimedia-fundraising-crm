<?php
use queue2civicrm\banner_history\BannerHistoryQueueConsumer;

use SmashPig\Core\Context;
use SmashPig\Core\QueueConsumers\BaseQueueConsumer;

/**
 * @group Queue2Civicrm
 */
class BannerHistoryTest extends BaseWmfDrupalPhpUnitTestCase {

	/**
	 * @var BannerHistoryQueueConsumer
	 */
	protected $consumer;

	public function setUp() {
		parent::setUp();
		$config = TestingSmashPigDbQueueConfiguration::instance();
		Context::initWithLogger( $config );
		$queue = BaseQueueConsumer::getQueue( 'test' );
		$queue->createTable( 'test' );
		$this->consumer = new BannerHistoryQueueConsumer(
			'test'
		);
	}

	public function testValidMessage() {
		$msg = array(
			'banner_history_id' => substr(
				md5( mt_rand() . time() ), 0, 16
			),
			'contribution_tracking_id' => strval( mt_rand() ),
		);
		$this->consumer->processMessage( $msg );
		// check for thing in db
	}

	/**
	 * @expectedException WmfException
	 */
	public function testBadContributionId() {
		$msg = array(
			'banner_history_id' => substr(
				md5( mt_rand() . time() ), 0, 16
			),
			'contribution_tracking_id' => '1=1; DROP TABLE students;--',
		);
		$this->consumer->processMessage( $msg );
	}

	/**
	 * @expectedException WmfException
	 */
	public function testBadHistoryId() {
		$msg = array(
			'banner_history_id' => '\';GRANT ALL ON drupal.* TO \'leet\'@\'haxx0r\'',
			'contribution_tracking_id' => strval( mt_rand() ),
		);
		$this->consumer->processMessage( $msg );
	}
}

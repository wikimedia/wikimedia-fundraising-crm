<?php
use queue2civicrm\fredge\AntifraudQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\QueueConsumers\BaseQueueConsumer;
use SmashPig\Tests\QueueTestConfiguration;

/**
 * @group Queue2Civicrm
 */
class AntifraudQueueTest extends BaseWmfDrupalPhpUnitTestCase {

	/**
	 * @var AntifraudQueueConsumer
	 */
	protected $consumer;

	public function setUp() {
		parent::setUp();
		$config = QueueTestConfiguration::instance();
		Context::initWithLogger( $config );
		$queue = BaseQueueConsumer::getQueue( 'test' );
		$queue->createTable( 'test' );
		$this->consumer = new AntifraudQueueConsumer(
			'test'
		);
	}

	public function testValidMessage() {
		$message = json_decode(
			file_get_contents( __DIR__ . '/../data/payments-antifraud.json'),
			true
		);
		$ctId = mt_rand();
		$oId = $ctId . '.0';
		$message['contribution_tracking_id'] = $ctId;
		$message['order_id'] = $oId;
		$this->consumer->processMessage( $message );

		$this->compareMessageWithDb( $message, $message['score_breakdown'] );
	}

	/**
	 * The first message for a ct_id / order_id pair needs to be complete
	 *
	 * @expectedException FredgeDataValidationException
	 */
	public function testIncompleteMessage() {
		$message = json_decode(
			file_get_contents( __DIR__ . '/../data/payments-antifraud.json'),
			true
		);
		unset( $message['user_ip'] );
		$this->consumer->processMessage( $message );
	}

	public function testCombinedMessage() {
		$message1 = json_decode(
			file_get_contents( __DIR__ . '/../data/payments-antifraud.json'),
			true
		);
		$message2 = json_decode(
			file_get_contents( __DIR__ . '/../data/payments-antifraud.json'),
			true
		);
		$ctId = mt_rand();
		$oId = $ctId . '.0';
		$message1['contribution_tracking_id'] = $ctId;
		$message2['contribution_tracking_id'] = $ctId;
		$message1['order_id'] = $oId;
		$message2['order_id'] = $oId;
		$message1['score_breakdown'] = array_slice(
			$message1['score_breakdown'], 0, 4
		);
		$message2['score_breakdown'] = array_slice(
			$message2['score_breakdown'], 4, 4
		);
		$this->consumer->processMessage( $message1 );

		$dbEntries = $this->getDbEntries( $ctId, $oId );
		$this->assertEquals( 4, count( $dbEntries ) );

		$this->consumer->processMessage( $message2 );

		$breakdown = array_merge(
			$message1['score_breakdown'], $message2['score_breakdown']
		);

		$this->compareMessageWithDb( $message1, $breakdown );
	}

	protected function compareMessageWithDb( $common, $breakdown ) {
		$dbEntries = $this->getDbEntries(
			$common['contribution_tracking_id'], $common['order_id']
		);
		$this->assertEquals( 8, count( $dbEntries ) );
		$fields = array(
			'gateway',  'validation_action', 'payment_method',
			'risk_score', 'server'
		);
		foreach ( $fields as $field ) {
			$this->assertEquals( $common[$field], $dbEntries[0][$field] );
		}
		$this->assertEquals( ip2long( $common['user_ip'] ), $dbEntries[0]['user_ip'] );
		$this->assertEquals(
			$common['date'], wmf_common_date_civicrm_to_unix( $dbEntries[0]['date'] )
		);
		foreach ( $dbEntries as $score ) {
			$name = $score['filter_name'];
			$this->assertEquals(
				$breakdown[$name], $score['fb_risk_score'], "Mismatched $name score"
			);
		}
	}

	protected function getDbEntries( $ctId, $orderId ) {
		$query = Database::getConnection( 'default', 'fredge' )
			->select( 'payments_fraud', 'f' );
		$query->join(
			'payments_fraud_breakdown', 'fb', 'fb.payments_fraud_id = f.id'
		);
		return $query
			->fields( 'f', array(
				'contribution_tracking_id', 'gateway', 'order_id',
				'validation_action', 'user_ip', 'payment_method',
				'risk_score', 'server', 'date'
			) )
			->fields( 'fb', array( 'filter_name', 'risk_score' ) )
			->condition( 'contribution_tracking_id', $ctId )
			->condition( 'order_id', $orderId )
			->execute()
			->fetchAll( PDO::FETCH_ASSOC );
	}
}

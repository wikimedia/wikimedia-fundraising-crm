<?php

use Civi\Api4\PaymentsFraud;
use Civi\Api4\PaymentsFraudBreakdown;
use Civi\WMFQueue\AntifraudQueueConsumer;
use Civi\WMFException\FredgeDataValidationException;

/**
 * @group Queue2Civicrm
 */
class AntifraudQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var AntifraudQueueConsumer
   */
  protected $consumer;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new AntifraudQueueConsumer(
      'payments-antifraud'
    );
  }

  public function tearDown(): void {
    PaymentsFraud::delete(FALSE)->addWhere('user_ip', '=', 16909060)->execute();
    PaymentsFraudBreakdown::delete(FALSE)->addWhere('payments_fraud_id.user_ip', 'IS NULL')->execute();
    parent::tearDown();
  }

  /**
   * Test the message is not rejected.
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function testValidMessage() {
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-antifraud.json'),
      TRUE
    );
    $ctId = mt_rand();
    $oId = $ctId . '.0';
    $message['contribution_tracking_id'] = $ctId;
    $message['order_id'] = $oId;
    $this->consumer->processMessage($message);

    $this->compareMessageWithDb($message, $message['score_breakdown']);
  }

  /**
   * If the risk score is more than 100 million it should be set to 100 mil.
   *
   * This is effectively 'infinite risk' and our db can't cope with
   * real value! '3.5848273556811E+38'
   */
  public function testFraudMessageWithOutOfRangeScore(): void {
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-antifraud-high.json'),
      TRUE
    );
    $ctId = mt_rand();
    $oId = $ctId . '.0';
    $message['contribution_tracking_id'] = $ctId;
    $message['order_id'] = $oId;
    $this->consumer->processMessage($message);

    $message['risk_score'] = 100000000;

    $this->compareMessageWithDb($message, $message['score_breakdown']);
  }

  /**
   * The first message for a ct_id / order_id pair needs to be complete
   *
   *
   * @throws \Civi\WMFException\WMFException
   */
  public function testIncompleteMessage(): void {
    $this->expectException(FredgeDataValidationException::class);
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-antifraud.json'),
      TRUE
    );
    unset($message['user_ip']);
    $this->consumer->processMessage($message);
  }

  public function testCombinedMessage(): void {
    $message1 = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-antifraud.json'),
      TRUE
    );
    $message2 = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-antifraud.json'),
      TRUE
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
    $this->consumer->processMessage($message1);

    $dbEntries = $this->getDbEntries($ctId, $oId);
    $this->assertEquals(4, count($dbEntries));

    $this->consumer->processMessage($message2);

    $breakdown = array_merge(
      $message1['score_breakdown'], $message2['score_breakdown']
    );

    $this->compareMessageWithDb($message1, $breakdown);
  }

  protected function compareMessageWithDb($common, $breakdown): void {
    $dbEntries = $this->getDbEntries(
      $common['contribution_tracking_id'], $common['order_id']
    );
    $this->assertCount(count($breakdown), $dbEntries);
    $fields = [
      'gateway',
      'validation_action',
      'payment_method',
      'risk_score',
      'server',
    ];
    foreach ($fields as $field) {
      $this->assertEquals($common[$field], $dbEntries[0][$field]);
    }
    $this->assertEquals(ip2long($common['user_ip']), $dbEntries[0]['user_ip']);
    $this->assertEquals(
      $common['date'], $this->wmf_common_date_civicrm_to_unix($dbEntries[0]['date'])
    );
    foreach ($dbEntries as $score) {
      $name = $score['filter_name'];
      $expectedScore = $breakdown[$name] <= 100000000 ? $breakdown[$name] : 100000000;
      $this->assertEquals(
        (float) $expectedScore, (float) $score['fb_risk_score'], "Mismatched $name score"
      );
    }
  }

  protected function getDbEntries($ctId, $orderId) {
    $query = Database::getConnection('default', 'fredge')
      ->select('payments_fraud', 'f');
    $query->join(
      'payments_fraud_breakdown', 'fb', 'fb.payments_fraud_id = f.id'
    );
    return $query
      ->fields('f', [
        'contribution_tracking_id',
        'gateway',
        'order_id',
        'validation_action',
        'user_ip',
        'payment_method',
        'risk_score',
        'server',
        'date',
      ])
      ->fields('fb', ['filter_name', 'risk_score'])
      ->condition('contribution_tracking_id', $ctId)
      ->condition('order_id', $orderId)
      ->execute()
      ->fetchAll(PDO::FETCH_ASSOC);
  }

}

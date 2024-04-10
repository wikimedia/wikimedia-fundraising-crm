<?php

namespace Civi\WMFQueue;

use Civi\Api4\PaymentsFraud;
use Civi\Api4\PaymentsFraudBreakdown;
use Civi\WMFException\FredgeDataValidationException;

/**
 * @group queues
 */
class AntifraudQueueTest extends BaseQueueTestCase {

  protected string $queueName = 'payments-antifraud';

  protected string $queueConsumer = 'Antifraud';

  /**
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    PaymentsFraud::delete(FALSE)->addWhere('user_ip', '=', 16909060)->execute();
    PaymentsFraudBreakdown::delete(FALSE)->addWhere('payments_fraud_id.user_ip', 'IS NULL')->execute();
    parent::tearDown();
  }

  /**
   * Test the message is not rejected.
   *
   * @throws \CRM_Core_Exception
   */
  public function testValidMessage(): void {
    $message = $this->getAntiFraudMessage();
    $this->processMessage($message);
    $this->compareFraudMessageWithDb($message, $message['score_breakdown']);
  }

  /**
   * If the risk score is more than 100 million it should be set to 100 mil.
   *
   * This is effectively 'infinite risk' and our db can't cope with
   * real value! '3.5848273556811E+38'
   *
   * @throws \CRM_Core_Exception
   */
  public function testFraudMessageWithOutOfRangeScore(): void {
    $message = $this->getAntiFraudMessage();
    $message['risk_score'] = 500000000;
    $this->processMessage($message);
    $this->compareFraudMessageWithDb(['risk_score' => 100000000] + $message, $message['score_breakdown']);
  }

  /**
   * The first message for a contribution_tracking_id / order_id pair needs to be complete.
   */
  public function testIncompleteMessage(): void {
    $this->expectException(FredgeDataValidationException::class);
    $message = $this->getAntiFraudMessage();
    unset($message['user_ip']);
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testCombinedMessage(): void {
    $message1 = $this->getAntiFraudMessage();
    $message2 = $this->getAntiFraudMessage();
    $message1['score_breakdown'] = array_slice(
      $message1['score_breakdown'], 0, 4
    );
    $message2['score_breakdown'] = array_slice(
      $message2['score_breakdown'], 4, 4
    );
    $this->processMessage($message1);
    $paymentsFraudBreakdowns = PaymentsFraudBreakdown::get(FALSE)
      ->addWhere('payments_fraud_id.contribution_tracking_id', '=', $message1['contribution_tracking_id'])
      ->addWhere('payments_fraud_id.order_id', '=', $message1['order_id'])
      ->execute();
    $this->assertCount(4, $paymentsFraudBreakdowns);

    $this->processMessage($message2);

    $breakdown = array_merge(
      $message1['score_breakdown'], $message2['score_breakdown']
    );

    $this->compareFraudMessageWithDb($message1, $breakdown);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function compareFraudMessageWithDb(array $expected, array $breakdown): void {
    $paymentsFraud = PaymentsFraud::get(FALSE)
      ->addWhere('contribution_tracking_id', '=', $expected['contribution_tracking_id'])
      ->addWhere('order_id', '=', $expected['order_id'])->execute()->first();
    $this->assertEquals($expected['validation_action'], $paymentsFraud['validation_action']);
    $this->assertEquals($expected['gateway'], $paymentsFraud['gateway']);
    $this->assertEquals($expected['payment_method'], $paymentsFraud['payment_method']);
    $this->assertEquals($expected['server'], $paymentsFraud['server']);
    $this->assertEquals($expected['risk_score'], $paymentsFraud['risk_score']);
    $this->assertEquals(ip2long($expected['user_ip']), $paymentsFraud['user_ip']);
    $this->assertEquals($expected['date'], strtotime($paymentsFraud['date']));
    $paymentsBreakDown = PaymentsFraudBreakdown::get(FALSE)
      ->addWhere('payments_fraud_id.contribution_tracking_id', '=', $expected['contribution_tracking_id'])
      ->execute();
    $this->assertCount(count($breakdown), $paymentsBreakDown);

    foreach ($paymentsBreakDown as $score) {
      $name = $score['filter_name'];
      $expectedScore = min($breakdown[$name], 100000000);
      $this->assertEquals((float) $expectedScore, (float) $score['risk_score'], "Mismatched $name score");
    }
  }

  /**
   * Get generic message for the anti-fraud queue.
   *
   * @return array
   */
  protected function getAntiFraudMessage(): array {
    $message = $this->loadMessage('payments-antifraud');
    $message['contribution_tracking_id'] = 123456;
    $message['order_id'] = '123456.0';
    return $message;
  }

}

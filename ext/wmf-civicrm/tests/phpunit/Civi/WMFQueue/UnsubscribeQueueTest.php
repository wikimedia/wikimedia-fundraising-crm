<?php

namespace Civi\WMFQueue;

/**
 * @group WMFQueue
 * @group Unsubscribe
 */
class UnsubscribeQueueTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'Unsubscribe';

  protected string $queueName = 'unsubscribe';

  /**
   * @var string
   */
  protected string $email = 'testUnsubscribe@example.net';

  public function setUp(): void {
    parent::setUp();
    $this->createIndividual();
  }

  /**
   */
  public function testContactIsUnsubscribed(): void {
    $subscribed = $this->getContact();
    $this->createEmail();

    //check out fixture contact is not already unsubscribed
    $this->assertEquals(0, $subscribed['is_opt_out']);

    //process the unsubscription message
    $this->processMessage($this->getMessage());

    // Confirm we've unsubscribed our fixture contact.
    $unsubscribed = $this->getContact();
    $this->assertEquals(1, $unsubscribed['is_opt_out']);
  }

  protected function getMessage() {
    $message = $this->loadMessage('unsubscribe');
    $message['contribution-id'] = $this->createTestEntity('Contribution', [
      'contact_id' => $this->getContactID(),
      'total_amount' => 2.34,
      'create_date' => '2017-01-01',
      'financial_type_id' => 1,
    ])['id'];
    $message['email'] = $this->email;
    return $message;
  }

  protected function createEmail($params = []): void {
    $params += [
      'email' => $this->email,
      'contact_id' => $this->getContactID(),
      'is_primary' => 1,
    ];
    $this->createTestEntity('Email', $params);
  }

}

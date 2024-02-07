<?php

use Civi\WMFQueue\UnsubscribeQueueConsumer;

/**
 * @group Queue2Civicrm
 * @group Unsubscribe
 */
class UnsubscribeTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var UnsubscribeQueueConsumer
   */
  protected $consumer;

  /**
   * @var int
   */
  protected $contactId;

  /**
   * @var string
   */
  protected $email;

  /**
   * @var \CiviFixtures
   */
  protected $fixtures;

  public function setUp(): void {
    parent::setUp();
    $this->contactId = $this->createIndividual();
    $this->email = 'testUnsubscribe' . mt_rand(1000, 10000000) . '@example.net';

    $this->consumer = new UnsubscribeQueueConsumer(
      'opt-in'
    );
  }

  public function testContactIsUnsubscribed() {
    $subscribed = $this->getContact();
    $this->createEmail();

    //check out fixture contact is not already unsubscribed
    $this->assertEquals(0, $subscribed['is_opt_out']);

    //process the unsubscription message
    $this->consumer->processMessage($this->getMessage());

    //confirm we've unsubscribed our fixture contact
    $unsubscribed = $this->getContact();
    $this->assertEquals(1, $unsubscribed['is_opt_out']);
  }

  public function tearDown(): void {
    parent::tearDown();
    $this->fixtures = NULL;
  }

  protected function getMessage() {
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/unsubscribe.json'),
      TRUE
    );
    $message['contribution-id'] = $this->callAPISuccess('Contribution', 'create', [
      'contact_id' => $this->contactId,
      'total_amount' => 2.34,
      'create_date' => '2017-01-01',
      'financial_type_id' => 1,
    ])['id'];
    $message['email'] = $this->email;
    return $message;
  }

  protected function createEmail($params = []) {
    $params += [
      'email' => $this->email,
      'contact_id' => $this->contactId,
      'is_primary' => 1,
    ];
    civicrm_api3('Email', 'create', $params);
  }

  protected function getContact() {
    return civicrm_api3('Contact', 'getSingle', [
      'id' => $this->contactId,
      'return' => [
        'is_opt_out',
      ],
    ]);
  }

}

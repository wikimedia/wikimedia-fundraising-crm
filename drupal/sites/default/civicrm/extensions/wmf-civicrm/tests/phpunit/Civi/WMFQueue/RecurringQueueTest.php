<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;
use Civi\Api4\Contribution;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFException\WMFException;

/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueTest extends BaseQueue {

  use ContactTestTrait;
  use Api3TestTrait;

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

  public function testNoSubscrId(): void {
    $this->expectExceptionCode(WMFException::INVALID_RECURRING);
    $this->expectException(WMFException::class);
    $message = $this->getRecurringPaymentMessage();
    $message['subscr_id'] = NULL;
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Ensure non-USD PayPal synthetic start message gets the right
   * currency imported
   *
   * @throws \CRM_Core_Exception
   * @throws \Random\RandomException
   */
  public function testPayPalMissingPredecessorNonUSD(): void {
    $email = random_int(0, 1000) . 'not-in-the-database@example.com';
    $message = $this->getRecurringPaymentMessage(
      [
        'currency' => 'CAD',
        'amount' => 10.00,
        'gateway' => 'paypal_ec',
        'subscr_id' => 'I-123456',
        'email' => $email,
      ]
    );

    $this->processMessage($message);
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', $email)
      ->execute()->single();
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute()->single();
    // ...and it should have the correct currency
    $this->assertEquals('CAD', $contributionRecur['currency']);
  }

  /**
   * Deal with a bad situation caused by PayPal's botched subscr_id migration.
   * See comment on RecurringQueueConsumer::importSubscriptionPayment.
   *
   * @throws \CRM_Core_Exception
   */
  public function testScrewySubscrId(): void {
    $email = 'test_recur_' . mt_rand() . '@example.org';
    // Set up an old-style PayPal recurring subscription with S-XXXX subscr_id
    $subscr_id = 'S-' . mt_rand();
    $ctId = $this->addContributionTrackingRecord();
    $values = $this->processRecurringSignup($subscr_id, [
      'gateway' => 'paypal',
      'email' => $email,
      'contribution_tracking_id' => $ctId,
    ]);

    // Import an initial payment with consistent gateway and subscr_id
    $values['email'] = $email;
    $values['gateway'] = 'paypal';
    $oldStyleMessage = $this->getRecurringPaymentMessage($values);

    $this->processMessage($oldStyleMessage);

    // New payment comes in with subscr ID format that we associate
    // with paypal_ec, so we mis-tag the gateway.
    $new_subscr_id = 'I-' . mt_rand();
    $values['subscr_id'] = $new_subscr_id;
    $values['gateway'] = 'paypal_ec';
    $values['gateway_txn_id'] = 456789;
    $newStyleMessage = $this->getRecurringPaymentMessage($values);

    $this->processMessage($newStyleMessage);
    // It should be imported as a paypal donation, not paypal_ec
    $contribution = $this->getContributionForMessage(['gateway' => 'paypal'] + $newStyleMessage);

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->execute()->single();

    // Finally, we should have stuck the new ID in the processor_id field
    $this->assertEquals($new_subscr_id, $contributionRecur['processor_id']);
  }

  /**
   * Process the original recurring sign up message.
   *
   * @param string $subscr_id
   * @param array $overrides
   *
   * @return array
   */
  private function processRecurringSignup(string $subscr_id, array $overrides = []): array {
    $values = $overrides + ['subscr_id' => $subscr_id];
    $this->processMessage($this->getRecurringSignupMessage($values));
    return $values;
  }

}

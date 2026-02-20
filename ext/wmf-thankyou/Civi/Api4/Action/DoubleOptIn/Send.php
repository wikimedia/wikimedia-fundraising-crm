<?php

namespace Civi\Api4\Action\DoubleOptIn;

use Civi\Api4\Activity;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WorkflowMessage;
use Civi\Omnimail\MailFactory;
use Civi\WMFThankYou\From;

/**
 * Class Send.
 *
 * Send a double opt-in email and record an activity
 *
 * @method $this setContactID(int $contactID)
 * @method $this setDisplayName(string $displayName)
 * @method $this setEmail(string $email)
 * @method $this setPreferredLanguage(string $preferredLanguage)
 */
class Send extends AbstractAction {
  /**
   * @var string
   *
   * @required
   */
   protected $displayName;

  /**
   * @var string
   *
   * @required
   */
  protected $email;

  /**
   * @var int
   *
   * @required
   */
  protected $contactID;

  /**
   * @var string
   */
  protected $preferredLanguage = 'en_US';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $message = WorkflowMessage::render(FALSE)
      ->setLanguage($this->preferredLanguage)
      ->setValues(['contactID' => $this->contactID])
      ->setWorkflow('double_opt_in')->execute()->first();

    $email = [
      'from_name' => From::getFromName('double_opt_in'),
      'from_address' => From::getFromAddress('double_opt_in'),
      'to_name' => $this->displayName,
      'to_address' => $this->email,
      'locale' => $this->preferredLanguage,
      'html' => $message['html'],
      'subject' => $message['subject'],
    ];

    \Civi::log('wmf')->info(
      'thank_you: Sending double opt-in email to: {to_address}',
      ['to_address' => $email['to_address']]
    );
    $sendResult = [
      'email' => $email,
      'is_success' => FALSE
    ];
    if (MailFactory::singleton()->send($email, [])) {
      $sendResult['is_success'] = TRUE;
      Activity::create(FALSE)->setValues([
        'source_contact_id' => $this->contactID,
        'target_contact_id' => $this->contactID,
        'activity_type_id:name' => 'Email',
        'activity_date_time' => 'now',
        'subject' => $message['subject'],
        'details' => $message['html'],
        'status_id:name' => 'Completed',
      ])->execute();
    }
    $result[] = $sendResult;
  }

}

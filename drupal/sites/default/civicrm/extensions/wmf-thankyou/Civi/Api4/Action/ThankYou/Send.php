<?php

namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\ThankYou;
use Civi\Api4\WMFLink;
use Civi\WMFException\WMFException;
use Civi\Omnimail\MailFactory;
use Civi\WMFMailTracking\CiviMailQueueRecord;
use Civi\WMFMailTracking\CiviMailStore;
use Civi\WMFMailTracking\CiviMailingInsertException;
use Civi\WMFMailTracking\CiviMailingMissingException;
use Civi\WMFMailTracking\CiviQueueInsertException;
use Civi\WMFThankYou\From;

/**
 * Class Render.
 *
 * Get the content of the thank you.
 *
 * @method array getParameters() Get the parameters.
 * @method $this setParameters(array $params) Get the parameters.
 * @method $this setDisplayName(string $displayName)
 * @method $this setTemplateName(string $templateName)
 * @method $this setEmail(string $email)
 * @method $this setActivityType(string $activityType)
 * @method string getActivityType()
 * @method $this setContactID(int $contactID)
 * @method $this setContributionID(int $contributionID)
 * @method $this setMaxRenderAttempts(int $max)
 * @method int getMaxRenderAttempts()
 */
class Send extends AbstractAction {

  /**
   * These are the parameters that have been historically passed into thank_you_send_mail.
   *
   * As we refine this we will likely get rid of params in favour of more specific / relevant values.
   *
   * @var array
   */
   public array $parameters = [];

  /**
   * @var string
   */
   public $displayName;

  /**
   * @var string
   */
   public $templateName;

  /**
   * @var int
   *
   * @required
   */
   public $contributionID;

  /**
   * @var string
   */
   public $email;

   public $contactID;

   protected int $maxRenderAttempts = 3;

   private $preferredLanguage;

   protected string $activityType = 'Thank you email';

  /**
   * @return mixed
   */
  private function getContactID(): int {
    if (!isset($this->contactID)) {
      $this->contactID = $this->getParameters()['contact_id'] ?? $this->getContact()['id'];
    }
    return $this->contactID;
  }

  /**
   * Get the contribution ID.
   *
   * Transitionally look it up in params but later the calling function should set it.
   *
   * @return int
   */
  protected function getContributionID(): int {
    return $this->contributionID;
  }

  protected function getTemplateName() : string {
    if (isset($this->templateName)) {
      return $this->templateName;
    }
    $this->getContact();
    return $this->templateName;
  }

  /**
   * Get the email.
   *
   * Transitionally look it up in params but later the calling function should set it.
   * Fall back to getting it based on the contribution ID (if the calling function does
   * not already know it then the preference is to look it up here.)
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getEmail(): string {
    if (!isset($this->email)) {
      $this->email = $this->getParameters()['recipient_address'] ?? NULL;
      if (!$this->email) {
        $this->getContact();
      }
    }
    if (!$this->email) {
      throw new \CRM_Core_Exception('no valid email');
    }
    return $this->email;
  }

  /**
   * Get the display name.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getDisplayName(): string {
    if (!isset($this->displayName)) {
      $this->getContact();
    }
    return $this->displayName;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $params = $this->getParameters();
    $renderAttempts = 0;
    $email_success = FALSE;

    // Loop ends with 'break' or 'throw'
    while (TRUE) {
      try {
        \Civi::log('wmf')->info('thank_you: Calling ThankYou::render');
        $rendered = ThankYou::render(FALSE)
          // @todo switch to passing in 'raw' contact language.
          ->setLanguage($this->getLanguage())
          ->setTemplateName($this->getTemplateName())
          ->setTemplateParameters($params)
          ->setContributionID($this->getContributionID())
          ->execute()->first();
        \Civi::log('wmf')->info('thank_you: Done ThankYou::render');
        $html = $rendered['html'];
        $subject = $rendered['subject'];

        if (!$html || !$subject) {
          $msg = "HTML rendering of template failed in {$params['locale']}.";
          throw new WMFException(WMFException::UNKNOWN, $msg, ['thank_you_params' => $params]);
        }
        break;
      }
      catch (\Exception $ex) {
        $renderAttempts++;
        if ($renderAttempts >= $this->getMaxRenderAttempts()) {
          throw $ex;
        }
      }
    }

    $civi_queue_record = NULL;
    try {
      $create_civi_mail = \Civi::settings()->get('thank_you_add_civimail_records');
      $rate = \Civi::settings()->get('thank_you_civimail_rate');
      if ($create_civi_mail && mt_rand(0, 10000) <= $rate * 10000 && isset($params['contact_id']) && $params['contact_id'] > 0) {
        $civi_queue_record = $this->trackMessage(
          $this->getEmail(),
          $params['contact_id'],
          $subject,
          $html
        );
      }
    }
    catch (\Exception $ex) {
      \Civi::log('wmf')->warning('Failed to create civimail record for TY message to ' . $params['recipient_address']);
    }
    try {
      $email = [
        'from_name' => From::getFromName($this->getTemplateName()),
        'from_address' => From::getFromAddress($this->getTemplateName()),
        'to_name' => $this->getDisplayName(),
        'to_address' => $this->getEmail(),
        'locale' => $this->getLanguage(),
        'html' => $html,
        'subject' => $subject,
        'reply_to' => $civi_queue_record ? $civi_queue_record->getVerp() : "ty." . $this->getContactID() . '.' . $this->getContributionID() . "@donate.wikimedia.org",
      ];

      \Civi::log('wmf')->info('thank_you: Sending ty email to: {to_address}', ['to_address' => $email['to_address']]);
      $email_success = MailFactory::singleton()->send(
        $email,
        $this->getHeaders()
      );
      if (!$email_success) {
        $msg = 'Thank you mail failed for contribution id: ' . $this->getContributionID() . " to " . $this->getEmail();
        throw new WMFException(WMFException::BAD_EMAIL, $msg);
      }
      \Civi::log('wmf')->info('thank_you: {activity_type} sent successfully to contact_id {contact_id} for contribution id: {contribution_id} to {recipient_address}', [
        'contact_id' => $this->getContactID(),
        'contribution_id' => $this->getContributionID(),
        'recipient_address' => $this->getEmail(),
        'activity_type' => $this->getActivityType(),
      ]);
      if ($this->isThankYou()) {
        \Civi::log('wmf')->info('thank_you: Updating TY send date to: {date}', ['date' => date('Y-m-d H:i:s')]);
        Contribution::update(FALSE)
          ->addWhere('id', '=', $this->getContributionID())
          ->addValue('thankyou_date', 'now')
          ->execute();
      }
      $this->createActivity($subject, $html);
    }
    catch (\Exception $e) {
      if (str_contains($e->getMessage(), 'Invalid address:')) {
        $this->setNoThankYou('failed: BAD_EMAIL');
      }
      else {
        $debug = array_merge($email ?? [], ['html' => '', 'subject' => '']);
        \Civi::log('wmf')->error('thank_you: Sending thank you message failed with {exception_type} exception for contribution: {params} {debug} {error_message}', [
          'params' => $params,
          'debug' => $debug,
          'exception_type' => get_class($e),
          'error_message' => $e->getMessage(),
        ]);

        $msg = "UNHANDLED EXCEPTION SENDING THANK YOU MESSAGE\n" . __FUNCTION__
          . "\n\n" . $e->getMessage() . "\n\n" . $e->getTraceAsString();

        throw new WMFException(WMFException::EMAIL_SYSTEM_FAILURE, $msg, $debug, $e);
      }
    }

    if ($civi_queue_record) {
      try {
        $civi_queue_record->markDelivered();
      }
      catch (\Exception $ex) {
        \Civi::log('wmf')->warning('Failed to mark civimail record delivered for TY message to ' . $params['recipient_address']);
      }
    }
    $result[] = ['is_success' => $email_success];
  }

  /**
   * Get the email headers.
   *
   * This includes the one-click unsubscribe.
   *
   * @return string[]
   */
  public function getHeaders(): array {
    $links = WMFLink::getUnsubscribeURL(FALSE)
      ->setContributionID($this->getContributionID())
      ->setEmail($this->getEmail())
      ->setContactID($this->getContactID())
      ->setLanguage($this->getLanguage())
      ->execute()->first();
    // @todo - the one_click currently is the same as the user unsubscribe but
    // we are working on changing that. For now the unsubscribe_url_one_click is
    // the status quo.
    return [
      'List-Unsubscribe' => '<' . $links['unsubscribe_url_one_click'] . '>',
      // 'List-Unsubscribe-Post'  => '<' . $links['unsubscribe_url_post'] . '>',
    ];
  }


  /**
   * Add record of the sent email to CiviMail
   *
   * @param string $email recipient address
   * @param int $contact_id recipient contact id
   * @param string $subject subject header to insert in case of missing mailing
   * @param string $html HTML email body, which should have a template info
   *   comment
   *
   * @return CiviMailQueueRecord mail queue record with VERP
   *   header
   */
  private function trackMessage($email, $contact_id, $subject, $html) {
    $civi_queue_record = NULL;
    $civimail_store = new CiviMailStore();
    try {
      try {
        $civi_mailing = $civimail_store->getMailing('thank_you');
      }
      catch (CiviMailingMissingException $e) {
        \Civi::log('wmf')->info(
          'thank_you: Thank you mailing missing - wtf'
        );
      }
      \Civi::log('wmf')->info('thank_you: Creating CiviMail record');
      $civi_queue_record = $civimail_store->addQueueRecord($civi_mailing, $email, $contact_id);
      \Civi::log('wmf')->info('thank_you: Done creating CiviMail record');
    }
    catch (CiviQueueInsertException $e) {
      \Civi::log('wmf')->info(
        'thank_you: CiviMail queue insert failed: {error_message}',
        ['error_message' => $e->getMessage()]
      );
    }
    catch (CiviMailingInsertException $e) {
      \Civi::log('wmf')->info(
        'Could not insert fallback mailing: {error_message}',
        ['error_message' => $e->getMessage()]
      );
    }
    return $civi_queue_record;
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function getContact(): ?array {
    $contact = Contribution::get(FALSE)
      ->addWhere('id', '=', $this->getContributionID())
      ->addWhere('contact_id.email_primary.on_hold', '=', FALSE)
      ->addSelect('contact_id.email_primary.email')
      ->addSelect('contact_id')
      ->addSelect('contact_id.display_name')
      ->addSelect('financial_type_id:name')
      ->addSelect('contact_id.preferred_language')
      ->execute()->first();
    if (!isset($this->contactID)) {
      $this->contactID = $contact['contact_id'];
    }
    if (!isset($this->email)) {
      $this->email = $contact['contact_id.email_primary.email'];
    }
    if (!isset($this->displayName)) {
      $this->displayName = (string) $contact['contact_id.display_name'];
    }
    if (!isset($this->preferredLanguage)) {
      $this->preferredLanguage = (string) $contact['contact_id.preferred_language'];
    }
    if (!isset($this->templateName)) {
      $this->templateName = $contact['financial_type_id:name'] === 'Endowment Gift' ? 'endowment_thank_you' : 'thank_you';
    }
    return ['id' => $contact['contact_id'], 'email_primary.email' => $contact['contact_id.email_primary.email']];
  }

  /**
   * @return string
   */
  public function getLanguage(): string {
    if (isset($this->getParameters()['language'])) {
      return $this->getParameters()['language'];
    }
    if (isset($params['locale'])) {
      return Civi\WMFHelper\Language::getLanguageCode($params['locale']);
    }
    if (isset($this->preferredLanguage)) {
      return $this->preferredLanguage;
    }
    $this->getContact();
    return $this->preferredLanguage ?? 'en_US';
  }

  private function setNoThankYou(string $reason): void {
    Contribution::update(FALSE)
      ->addValue('contribution_extra.no_thank_you', $reason)
      ->addWhere('id', '=', $this->getContributionID())
      ->execute();
  }

  /**
   * @param string $subject
   * @param string $html
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  protected function createActivity(string $subject, string $html): void {
    Activity::create(FALSE)->setValues([
      'source_contact_id' => $this->getContactID(),
      'target_contact_id' => $this->getContactID(),
      'activity_type_id:name' => $this->getActivityType(),
      'activity_date_time' => 'now',
      'subject' => $subject,
      'details' => $html,
      'status_id:name' => 'Completed',
    ])->execute();
  }

  /**
   * @return bool
   */
  private function isThankYou(): bool {
    return $this->getActivityType() === 'Thank you email';
  }

}

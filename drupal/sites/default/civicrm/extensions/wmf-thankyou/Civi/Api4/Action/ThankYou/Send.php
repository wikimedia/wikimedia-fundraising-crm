<?php


namespace Civi\Api4\Action\ThankYou;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\ThankYou;
use Civi\WMFException\WMFException;
use Civi\Omnimail\MailFactory;
use Civi\WMFThankYou\From;

/**
 * Class Render.
 *
 * Get the content of the thank you.
 *
 * @method array getParameters() Get the parameters.
 * @method $this setParameters(array $params) Get the parameters.
 * @method $this setDisplayName(string $displayName)
 * @method string getDisplayName()
 * @method $this setTemplateName(string $templateName)
 * @method string getTemplateName()
 */
class Send extends AbstractAction {

  /**
   * These are the parameters that have been historically passed into thank_you_send_mail.
   *
   * As we refine this we will likely get rid of params in favour of more specific / relevant values.
   *
   * @var array
   */
   public $parameters;

  /**
   * @var string
   */
   public $displayName;

  /**
   * @var string
   */
   public $templateName;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $params = $this->getParameters();
    if (empty($params['language'])) {
      $params['language'] = Civi\WMFHelpers\Language::getLanguageCode($params['locale'] ?? 'en_US');
    }
    $renderAttempts = 0;
    $email_success = FALSE;

    // Loop ends with 'break' or 'throw'
    while(true) {
      try {
        $rendered = ThankYou::render(FALSE)
          // @todo switch to passing in 'raw' contact language.
          ->setLanguage($params['language'])
          ->setTemplateName($this->getTemplateName())
          ->setTemplateParameters($params)
          ->execute()->first();
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
        if ($renderAttempts >= MAX_RENDER_ATTEMPTS) {
          throw $ex;
        }
      }
    }

    $civi_queue_record = NULL;
    try {
      $create_civi_mail = \Civi::settings()->get('thank_you_add_civimail_records');
      $rate = \Civi::settings()->get('thank_you_civimail_rate');
      if ($create_civi_mail && mt_rand(0, 10000) <= $rate * 10000 && isset($params['contact_id']) && $params['contact_id'] > 0) {
        $civi_queue_record = thank_you_add_civi_queue(
          $params['recipient_address'],
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
        'to_address' => $params['recipient_address'],
        'locale' => $params['language'],
        'html' => $html,
        'subject' => $subject,
        'reply_to' => $civi_queue_record ? $civi_queue_record->getVerp() : "ty.{$params['contact_id']}.{$params['contribution_id']}@donate.wikimedia.org",
      ];

      \Civi::log('wmf')->info('thank_you: Sending ty email to: {to_address}', ['to_address' => $email['to_address']]);
      $email_success = MailFactory::singleton()->send(
        $email,
        ['List-Unsubscribe' => '<' . $params['unsubscribe_link'] . '>']
      );
    }
    catch (\PHPMailer\PHPMailer\Exception $e) {
      //TODO: don't assume phpmailer
      //TODO: something with the CiviMail queue record to indicate it failed;
      $debug = array_merge($email, ["html" => '', "plaintext" => '']);
      \Civi::log('wmf')->error('thank_you: Sending thank you message failed in phpmailer for contribution:
      {params} . "\n\n" .
      {error_message}', ['params' => $params, 'error_message' => $e->errorMessage()]);

      if (strpos($e->errorMessage(), "Invalid address:") === FALSE) {
        \Civi::log('wmf')->error('thank_you: PhpMailer died unexpectedly: {error_message} at {trace}', [
          'error_message' => $e->errorMessage(),
          'trace' => $e->getTraceAsString(),
        ]);
        $msg = "UNHANDLED PHPMAILER EXCEPTION SENDING THANK YOU MESSAGE\n"
          . __FUNCTION__ . "\n\n" . $e->errorMessage() . "\n\n"
          . $e->getTraceAsString();
        throw new WMFException(WMFException::EMAIL_SYSTEM_FAILURE, $msg, $debug);
      }
    }
    catch (\Exception $e) {
      $debug = array_merge($email, ["html" => '', "plaintext" => '']);
      \Civi::log('wmf')->error('thank_you: Sending thank you message failed with generic exception for contribution: {params} {debug} {error_message}', [
        'params' => $params,
        'debug' => $debug,
        'error_message' => $e->getMessage(),
      ]);

      $msg = "UNHANDLED EXCEPTION SENDING THANK YOU MESSAGE\n" . __FUNCTION__
        . "\n\n" . $e->getMessage() . "\n\n" . $e->getTraceAsString();

      throw new WMFException(WMFException::EMAIL_SYSTEM_FAILURE, $msg, $debug);
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

}

<?php
namespace Civi\Api4\Action\Omniphone;

use Civi\Api4\Action\HasClientTrait;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Omnimail\Omnimail;

/**
 * Opt a phone number into an SMS program by sending a virtual mobile originated (MO) message.
 *
 * @see https://developer.goacoustic.com/acoustic-campaign/reference/send-a-mobile-originated-mo-message
 *
 * @method $this setPhoneNumber(string $phoneNumber)
 * @method string getPhoneNumber()
 * @method $this setProgramID(string $programID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 *
 * @package Civi\Api4
 */
class OptIn extends AbstractAction {

  use HasClientTrait;

  /**
   * Phone number, including country code and without a leading plus, eg. 14155552671.
   *
   * @var string
   */
  protected $phoneNumber;

  /**
   * Acoustic program the phone number is opted into.
   *
   * @var string
   */
  protected $programID;

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  public function getProgramID(): string {
    if (!$this->programID) {
      $this->programID = \Civi::settings()->get('omnimail_sms_campaign_id');
    }
    return $this->programID;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function _run(Result $result): void {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($this->getMailProvider(), \CRM_Omnimail_Helper::getCredentials([
      'mail_provider' => $this->getMailProvider(),
      'client' => $this->getClient(),
    ]));
    $result[] = $mailer->smsOptInRequest([
      'phone' => $this->getPhoneNumber(),
      'programID' => $this->getProgramID(),
    ])->getResponse();
  }

  public function fields(): array {
    return [];
  }

}

<?php

namespace Civi;

class WMFDonationQueueMessage {

  /**
   * @var array WMF message with keys (incomplete list)
   *  - recurring
   *  - contribution_recur_id
   *  - subscr_id
   *  - recurring_payment_token
   */
  protected $message;

  /**
   * @param $message
   */
  public function __construct($message) {
    $this->message = $message;
  }

  /**
   * Is it recurring - we would be using the child class if it is.
   *
   * @return bool
   */
  public function isRecurring(): bool {
    return FALSE;
  }

  public function isInvalidRecurring(): bool {
    return FALSE;
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithSubscriberID(): bool {
    return FALSE;
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithPaymentToken(): bool {
    return FALSE;
  }

  public static function getWMFMessage($message) {
    if (!empty($message['recurring']) || !empty($message['contribution_recur_id'])) {
      $messageObject = new WMFDonationQueueRecurMessage($message);
    }
    else {
      $messageObject = new WMFDonationQueueMessage($message);
    }
    return $messageObject;
  }

}

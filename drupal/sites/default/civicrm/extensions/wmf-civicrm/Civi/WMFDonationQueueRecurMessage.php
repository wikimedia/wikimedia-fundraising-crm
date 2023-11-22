<?php

namespace Civi;

class WMFDonationQueueRecurMessage extends WMFDonationQueueMessage {

  /**
   * True if recurring is in the incoming array or a contribution_recur_id is present.
   *
   * @return bool
   */
  public function isRecurring(): bool {
    return TRUE;
  }

  public function isInvalidRecurring(): bool {
    return empty($this->message['recurring_payment_token']) && empty($this->message['subscr_id']);
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithSubscriberID(): bool {
    return !empty($this->message['subscr_id']);
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithPaymentToken(): bool {
    return !empty($this->message['recurring_payment_token']);
  }

}

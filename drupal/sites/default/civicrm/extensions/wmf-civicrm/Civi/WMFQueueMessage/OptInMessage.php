<?php

namespace Civi\WMFQueueMessage;

use Civi\WMFException\WMFException;

class OptInMessage extends Message {

  public function normalize() : array {
    return $this->filterNull(array_merge($this->message, [
      'contact_id' => $this->getContactID(),
      'email' => $this->message['email'] ?? NULL,
      'Communication.opt_in' => TRUE,
      'Communication.do_not_solicit' => FALSE,
      'Communication.optin_campaign' => $this->message['utm_campaign'] ?? NULL,
      'Communication.optin_source' => $this->message['utm_source'] ?? NULL,
      'Communication.optin_medium' => $this->message['utm_medium'] ?? NULL,
      'is_opt_out' => FALSE,
      'do_not_email' => FALSE,
      'first_name' => $this->getFirstName(),
      'last_name' => $this->getLastName(),
    ]));
  }

  /**
   * Get the first name, but return nothing absent there being a last name.
   */
  public function getFirstName(): ?string {
    if (empty($this->message['first_name']) || empty($this->message['last_name'])) {
      return NULL;
    }
    return $this->message['first_name'];
  }

  /**
   * Get the last name, but return nothing absent there being a first name.
   */
  public function getLastName(): ?string {
    if (empty($this->message['first_name']) || empty($this->message['last_name'])) {
      return NULL;
    }
    return $this->message['last_name'];
  }

  /**
   * @throws WMFException
   */
  public function validate() : void {
    if (empty($this->message['email'])) {
      $error = "Required field not present! Dropping message on floor. Message: " . json_encode($this->message);
      throw new WMFException(WMFException::UNSUBSCRIBE, $error);
    }
  }

}

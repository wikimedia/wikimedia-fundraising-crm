<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\WMFLink;

trait UnsubscribeTrait {

  /**
   * @var string
   *
   * @scope tplParams as unsubscribe_link
   */
  protected $unsubscribeLink;

  /**
   * Get the unsubscribe link.
   *
   * Note that this is always determined here and any
   * passed in value is ignored. However, we need to
   * declare it as public or protected for the value to
   * be assigned to the template (by virtue of the scope annotation)
   *
   * @return string
   */
  public function getUnsubscribeLink(): string {
    return WMFLink::getUnsubscribeURL(FALSE)
      ->setContributionID($this->getContributionID())
      ->setEmail($this->getEmail())
      ->setContactID($this->getContactID())
      ->setMediawikiLocale($this->getShortLocale())
      ->execute()->first()['unsubscribe_url'];
  }

}

<?php

namespace Civi\WorkflowMessage;

/**
 * @method string getUrl()
 * @method $this setUrl(string $url)
 * @method string getTargetPage()
 * @method $this setTargetPage(string $targetPage)
 * @method bool getAccountFound()
 * @method $this setAccountFound(bool $accountFound)
 * @method bool getIsSecondaryEmail()
 * @method $this setIsSecondaryEmail(bool $isSecondaryEmail)
 * @method string|null getPreferencesUrl()
 * @method $this setPreferencesUrl(?string $preferencesUrl)
 * @method string|null getRetryUrl()
 * @method $this setRetryUrl(?string $retryUrl)
 */
class NewChecksumLinkMessage extends GenericWorkflowMessage {
  public const WORKFLOW = 'new_checksum_link';

  /**
   * Requested link
   *
   * @var string
   *
   * @scope tplParams
   */
  public $url;

  /**
   * Page the link is for
   *
   * @var string
   *
   * @scope tplParams
   */
  public $targetPage;

  /**
   * Did we work out whose account the requested address belongs to?
   *
   * @var bool
   *
   * @scope tplParams
   */
  public $accountFound = TRUE;

  /**
   * Was the link requested using an address that isn't the contact's primary one?
   *
   * @var bool
   *
   * @scope tplParams
   */
  public $isSecondaryEmail = FALSE;

  /**
   * Link to the email preference center, where a donor can change their email.
   *
   * @var string|null
   *
   * @scope tplParams
   */
  public $preferencesUrl;

  /**
   * Link back to the form for requesting a link, to try another address.
   *
   * @var string|null
   *
   * @scope tplParams
   */
  public $retryUrl;

  /**
   * Allow this message to be rendered with no contact at all.
   *
   * We send the 'we can't find your account' version of this email to an address
   * that matches no contact, so unlike most workflow messages there is sometimes
   * nobody to identify. The template falls back to a generic greeting. Anything
   * that does have a contact is validated as normal.
   *
   * @param array $errors
   *
   * @see \Civi\WMFQueue\NewChecksumLinkQueueConsumer
   */
  protected function validateExtra_contact(array &$errors) {
    if ($this->contactID !== NULL || $this->contact !== NULL) {
      parent::validateExtra_contact($errors);
    }
  }

}

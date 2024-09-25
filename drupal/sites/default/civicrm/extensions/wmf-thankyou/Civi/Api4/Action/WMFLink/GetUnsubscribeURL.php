<?php


namespace Civi\Api4\Action\WMFLink;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Get the appropriate unsubscribe url.
 *
 * Currently this only takes contribution ID and email - but it could
 * reasonably
 * support other options in future such as those from mailing_event_queue
 * records.
 *
 * @method $this setContributionID(int $contributionID)
 * @method string getEmail()
 * @method $this setEmail(string $email)
 * @method $this setMediawikiLocale(string $mediaWikiLocale)
 */
class GetUnsubscribeURL extends AbstractAction {

  /**
   * The contact's language.
   *
   * This might already be in a 2 character variant but should
   * cope (once refactored) with the value stored on the contact.
   *
   * @var string
   */
  protected $language;

  /**
   * The 2 character mediawiki locale.
   *
   * @var string|null
   */
  protected ?string $mediawikiLocale = NULL;

  /**
   * The email to put in the unsubscribe url.
   *
   * @var string $email
   */
  protected $email;

  /**
   * @var int|null
   */
  public ?int $contributionID = NULL;

  /**
   * Get the mediaWiki Locale to use.
   *
   * If this was not specifically provided we use the first
   * 2 characters of the civicrm language.
   *
   * @return string
   */
  public function getMediaWikiLocale(): string {
    return $this->mediawikiLocale ?: strtolower(substr($this->getLanguage(), 0, 2 ));
  }

  /**
   * Get the contribution ID, using a dummy of -1 for when it is not provided.
   *
   * In the token we use in recurring failure emails, for example, we do not
   * provide a contribution ID.
   *
   * @return int
   */
  public function getContributionID(): int {
    return $this->contributionID ?? -1;
  }

  /**
   * Get the main unsubscribe url.
   *
   * @return string
   */
  private function getUnsubscribeUrl(): string {
     return Civi::settings()->get('wmf_unsubscribe_url') . '?' . http_build_query([
         'p' => 'thankyou',
         'c' => $this->getContributionID(),
         'e' => $this->getEmail(),
         'h' => sha1(
           $this->getContributionID()
           . $this->getEmail()
           . \CRM_Utils_Constant::value('WMF_UNSUB_SALT')
         ),
         'uselang' => $this->getMediaWikiLocale(),
       ], '', '&');
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \Throwable
   */
  public function _run(Result $result): void {
    $result[] = [
      // We don't have all these urls .... yet.
      'unsubscribe_url' => $this->getUnsubscribeUrl(),
      'unsubscribe_url_one_click' => $this->getUnsubscribeUrl(),
      'unsubscribe_url_post' => '',
    ];
  }

}

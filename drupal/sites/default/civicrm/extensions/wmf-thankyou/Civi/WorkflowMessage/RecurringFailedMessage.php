<?php

namespace Civi\WorkflowMessage;

use Civi\WorkflowMessage\Traits\LocalizationTrait;

/**
 * This is the template class for previewing WMF recurring failure emails.
 *
 * @method $this setContributionRecurID(int $contributionRecurID)
 * @method int getContributionRecurID()
 * @method array getContributionRecur()
 *
 * @support template-only
 */
class RecurringFailedMessage extends GenericWorkflowMessage {

  public const WORKFLOW = 'recurring_failed_message';

  /**
   * Contribution Recur ID.
   *
   * @var int
   *
   * @scope tokenContext as contribution_recurId
   */
  public $contributionRecurID;

  /**
   * The recurring contribution.
   *
   * @var array
   *
   * @scope tokenContext as contribution_recur
   */
  public $contributionRecur;

  /**
   * Set the contribution recur.
   *
   * @param array $contributionRecur
   */
  public function setContributionRecur(array $contributionRecur): void {
    $this->contributionRecur = $contributionRecur;
    if (!$this->contributionRecurID && is_numeric($contributionRecur['id'] ?? NULL)) {
      $this->contributionRecurID = $contributionRecur['id'];
    }
  }

  /**
   * Set the contact.
   *
   * @param array $contact
   *
   * @return \Civi\WorkflowMessage\RecurringFailedMessage
   */
  public function setContact(array $contact): self {
    $contact['preferred_language'] = $this->getLocale();
    if (!$this->contactId) {
      $this->setContactId($contact['id']);
    }
    $this->contact = $contact;
    return $this;
  }

  /**
   * @param string|null $locale
   * @return $this
   */
  public function setLocale(?string $locale) {
    $this->locale = $locale;
    if (!empty($this->contact) && empty($this->contact['preferred_language'])) {
      $this->contact['preferred_language'] = $locale;
    }
    return $this;
  }

}

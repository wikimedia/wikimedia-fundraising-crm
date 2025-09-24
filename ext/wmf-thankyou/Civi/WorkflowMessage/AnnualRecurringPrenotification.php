<?php

namespace Civi\WorkflowMessage;

use Civi\Api4\Email;

/**
 * @method $this setEmail(string $email)
 */
class AnnualRecurringPrenotification extends RecurringMessage {
  use UnsubscribeTrait;

  public const WORKFLOW = 'annual_recurring_prenotification';

  /**
   * @var string|null
   */
  protected ?string $email = null;

  /**
   * Needed for the UnsubscribeTrait, but not actually used afaict
   * @return int
   */
  protected function getContributionID(): int {
    return 0;
  }

  protected function getEmail(): string {
    if (!$this->email) {
      $this->email = Email::get( FALSE )
        ->addWhere( 'contact_id', '=', $this->getContactID() )
        ->addWhere( 'is_primary', '=', TRUE )
        ->addSelect( 'email' )
        ->execute()->first()['email'];
    }
    return $this->email;
  }

  public function getShortLocale(): string {
    return substr((string) $this->getLocale(), 0, 2);
  }
}

<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;
use Civi\WMFQueueMessage\AuditMessage;

/**
 * Settle transaction.
 *
 * @method $this setValues(array $values)
 * @method $this setProcessSettlement(string $actionToTake)
 */
class Audit extends AbstractAction {

  /**
   * Settlement message.
   *
   * @var array
   */
  protected array $values = [];

  protected ?string $processSettlement = NULL;

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $message = new AuditMessage($this->values);

    if ($this->processSettlement) {
      // Here we would ideally queue but short term we will probably process in real time on specific files
      // as we test.
      // @todo - create queue option (after maybe some testing with this).
      // Commented out until a bit more testing done. (would need to be run deliberately
      // anyway).
      // WMFAudit::settle(FALSE)
      //  ->setValues($message->normalize())->execute();
    }
    $isMissing = !$message->getExistingContributionID();
    // @todo - we would ideally augment the missing messages here from the Pending table
    // allowing us to drop 'log_hunt_and_send'
    // also @todo if we are unable to find the extra data then queue to (e.g) a missing
    // queue - this would allow us to process each audit file only once & the transactions would
    // be 'in the system' from then on until resolved. I guess the argument for the files is that
    // if they do not get processed for any reason the file sits there until they are truly processed....

    $result[] = [
      'is_negative' => $message->isNegative(),
      'message' => $message->normalize(),
      'payment_method' => $message->getPaymentMethod(),
      'audit_message_type' => $message->getAuditMessageType(),
      'is_missing' => $isMissing,
      'is_settled' => $message->isSettled(),
    ];
  }

  public static function fields(): array {
    $message = new AuditMessage([]);
    return $message->getFields();
  }

}

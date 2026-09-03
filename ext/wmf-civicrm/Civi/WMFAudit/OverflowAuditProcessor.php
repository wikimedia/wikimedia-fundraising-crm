<?php

namespace Civi\WMFAudit;

use Civi\Api4\DAFGift;
use Civi\Api4\StockGift;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentProviders\Overflow\Audit\DepositsAudit;

class OverflowAuditProcessor extends BaseAuditProcessor {

  protected $name = 'overflow';

  protected string $queueMethod = 'immediate';

  protected function get_audit_parser(): DepositsAudit {
    return new DepositsAudit();
  }

  /**
   * Scope each run to one account's files (named 'overflow-<Account>-*.json'
   * by GetReport.php), keyed off the --gateway-account option this job was
   * invoked with. This has to be run once per account (matching the
   * civicrm_gateway_account rows 'overflow' and 'overflowendowment') so that
   * the batch reference/settlement_gateway - built generically from
   * audit_file_gateway + gatewayAccountString, see AuditMessage::
   * getAuditFileGateway() - comes out as 'overflow_...' or
   * 'overflowendowment_...' rather than mixing both accounts into one batch.
   */
  protected function regexForFilesToProcess(): string {
    $accountSegment = strtolower((string) $this->get_runtime_options('gateway_account')) === 'endowment' ? 'Endowment' : 'Foundation';
    return '/^overflow-' . preg_quote($accountSegment, '/') . '-.*\.json$/';
  }

  /**
   * Deposit ids don't sort chronologically, so fall back to file mtime.
   */
  protected function get_recon_file_sort_key($file): false|int|string {
    return $this->sortByModifiedDate($file);
  }

  /**
   * Overflow contributions do not create TransactionLog entries.
   */
  protected function isQueueableWithoutLogLookup(array $auditRecord): bool {
    return TRUE;
  }

  /**
   * Create the contribution directly from the audit row rather than queuing
   * it for a separate consumer to pick up.
   *
   * @param array $message
   *
   * @return void
   */
  protected function queueMissingAuditMessage(array $message): void {
    SourceFields::addToMessage($message);
    unset($message['transaction_details']);
    $entity = ($message['payment_method'] ?? NULL) === 'DAFpay' ? DAFGift::class : StockGift::class;
    $entity::queue(FALSE)
      ->setQueueMethod($this->queueMethod)
      ->setMessage($message)
      ->execute();
  }

}

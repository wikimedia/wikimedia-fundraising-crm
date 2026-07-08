<?php

namespace Civi\WMFAudit;

use Brick\Math\RoundingMode;
use Civi\Api4\DAFGift;
use Civi\Api4\MatchingGift;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\PaymentProviders\Chariot\Audit\DonationsAudit;

class ChariotAuditProcessor extends BaseAuditProcessor {

  protected $name = 'chariot';

  protected string $queueMethod = 'immediate';

  protected function get_audit_parser(): DonationsAudit {
    return new DonationsAudit();
  }

  /**
   */
  protected function regexForFilesToIgnore(): string {
    return '/.json/';
  }

  /**
   * Note: the output is only used to sort files in chronological order
   * The settlement detail report is named with sequential batch numbers
   * while the payments detail report has the date at the end of the name
   */
  protected function get_recon_file_sort_key($file): false|int|string {
    return $this->sortByModifiedDate($file);
  }

  protected function regexForFilesToProcess(): string {
    return '/.csv/';
  }

  /**
   * Is the information adequate to queue without attempting any log diving.
   *
   * Ideally this would always be true as we have deprecated looking in the log files.
   *
   * @param array $auditRecord
   *
   * @return bool
   */
  protected function isQueueableWithoutLogLookup(array $auditRecord): bool {
    return TRUE;
  }

  /**
   * Send the missing audit message to the appropriate queue.
   *
   * @param array $message
   *
   * @return void
   */
  protected function queueMissingAuditMessage(array $message): void {
    SourceFields::addToMessage($message);
    unset($message['transaction_details']);
    if ($message['is_matching_gift'] ?? FALSE) {
      MatchingGift::queue(FALSE)
        // Queue method will change over time.
        ->setQueueMethod($this->queueMethod)
        ->setMessage($message)
        ->execute();
    }
    else {
      DAFGift::queue(FALSE)
        // Queue method will change over time.
        ->setQueueMethod($this->queueMethod)
        ->setMessage($message)
        ->execute();
    }
  }

  /**
   * @param array $transaction
   * @return void
   */
  protected function addToBatch(array $transaction, string $file): void {
    parent::addToBatch($transaction, $file);
    $batchName = $transaction['settlement_batch_reference'];

    $matchingGift = $transaction['original_matching_gift_total_amount'] ?? '0.00';
    $individualGift = $transaction['original_individual_gift_total_amount'] ?? '0.00';
    if (!empty($individualGift) && $individualGift !== '0.00' && !empty($matchingGift) && $matchingGift !== '0.00') {
      // We have 2 transactions on the row - count 2
      $this->batches[$file][$batchName]['transaction_count']++;
    }
  }

}

<?php

namespace Civi\WMFBatch;

use League\Csv\Writer;

/**
 * Writes reclassification journals - reversing rows from the source
 * instance (foundation or endowment) and replacing them in the other one.
 *
 * Each journal gets a balancing row and the replacement rows take the
 * destination's location & vendor, and no cost centre. In the endowment
 * instance major gifts are journalled to the restricted account.
 */
class JournalWriter {

  /**
   * @param \League\Csv\Writer $sourceWriter
   *   The source instance's journal csv.
   * @param \League\Csv\Writer $destinationWriter
   *   The destination instance's journal csv.
   * @param string $sourceInstance
   *   foundation or endowment.
   * @param string $destinationInstance
   *   foundation or endowment.
   * @param array $gatewayAccount
   *   The batch's GatewayAccount, for the vendor codes.
   */
  public function __construct(
    private readonly Writer $sourceWriter,
    private readonly Writer $destinationWriter,
    private readonly string $sourceInstance,
    private readonly string $destinationInstance,
    private readonly array $gatewayAccount,
  ) {}

  /**
   * Reverse a row from the source instance & replace it in the destination.
   *
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  public function writeReclassificationJournals(array $row): void {
    $this->writeReversalJournal($row);
    $this->writeReplacementJournal($row);
  }

  /**
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  private function writeReversalJournal(array $row): void {
    $this->sourceWriter->insertOne(array_merge($row, ['DEBIT' => $row['CREDIT'], 'CREDIT' => $row['DEBIT']]));
    $this->sourceWriter->insertOne(array_merge($row, ['ACCT_NO' => $this->getBalancingAccount($this->sourceInstance)]));
  }

  /**
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  private function writeReplacementJournal(array $row): void {
    $row = array_merge($row, [
      'LOCATION_ID' => $this->isEndowment($this->destinationInstance) ? FinanceConstants::LOCATION_ID_ENDOWMENT : FinanceConstants::LOCATION_ID_WMF,
      'DEPT_ID' => '',
      // Vendor code is optional - e.g. wire & ach have no vendor in Intacct.
      'GLENTRY_VENDORID' => (string) ($this->gatewayAccount['vendor_code_' . $this->destinationInstance] ?? ''),
    ]);
    $balancingRow = array_merge($row, ['ACCT_NO' => $this->getBalancingAccount($this->destinationInstance), 'DEBIT' => $row['CREDIT'], 'CREDIT' => $row['DEBIT']]);
    if ($this->isEndowment($this->destinationInstance) && $this->isMajorGiftAccount($row['ACCT_NO'])) {
      $row['ACCT_NO'] = $this->getRestrictedMajorGiftsAccount();
    }
    $this->destinationWriter->insertOne($row);
    $this->destinationWriter->insertOne($balancingRow);
  }

  private function isEndowment(string $instance): bool {
    return $instance === 'endowment';
  }

  /**
   * Each instance uses the same balancing account whichever way the
   * transfer is going.
   */
  private function getBalancingAccount(string $instance): int {
    return $this->isEndowment($instance) ? FinanceConstants::GL_BALANCING_ACCOUNT_ENDOWMENT_INSTANCE : FinanceConstants::GL_BALANCING_ACCOUNT_WMF_INSTANCE;
  }

  private function isMajorGiftAccount($accountCode): bool {
    return in_array((int) $accountCode, FinanceConstants::GL_MAJOR_GIFTS_ACCOUNTS, TRUE);
  }

  private function getRestrictedMajorGiftsAccount(): string {
    return (string) FinanceConstants::GL_MAJOR_GIFTS_RESTRICTED;
  }

}

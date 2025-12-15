<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFBatch\BatchFile;
use CRM_Core_DAO;
use League\Csv\Writer;

/**
 * Validate the batch adds up.
 *
 * @method $this setBatchPrefix(string $batchPrefix)
 * @method $this setIsOutputCsv(bool $isOutputCsv)
 * @method $this setIsOutputSql(bool $isOutputSql)
 * @method $this setIsOutputRows(bool $isOutputRows)
 * @method $this setEmailSummaryAddress(string $email)
 */
class GenerateBatch extends AbstractAction {

  /**
   * Batch prefix.
   *
   * e.g adyen_1127 (the batch names are then adyen_1127_AUD)
   *
   * @var string
   */
  protected string $batchPrefix = '';

  /**
   * Is output rows.
   *
   * Generally rows might be useful in tests but are otherwise TMI.
   *
   * @var bool
   */
  protected bool $isOutputRows = FALSE;

  /**
   * Is a csv to be output.
   *
   * @var bool
   */
  protected bool $isOutputCsv = FALSE;

  /**
   * Is the generated sql to be output.
   *
   * @var bool
   */
  protected bool $isOutputSQL = FALSE;

  /**
   * The address to email a summary to.
   *
   * If provided a summary will be sent to this address.
   *
   * @var string|null
   */
  protected ?string $emailSummaryAddress = NULL;

  private Writer $writer;

  private array $detailWriters;

  private array $journalWriters;

  private array $headers = [];

  private array $incompleteRows = [];

  private array $log = [];

  private array $batchSummary = [];

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param Result $result
   *
   * @return void
   * @throws MoneyMismatchException
   * @throws UnknownCurrencyException
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $accountCodeClause = $this->getAccountClause();
    $deptIDClause = $this->getDeptIDClause();
    $restrictionsClause = $this->getRestrictionsClause();
    $vendorClause = $this->getVendorClause();

    $sql = "SELECT
    %2 as DATE,
    CONCAT('Contribution Revenue ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'), ' - ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y') ) as DESCRIPTION,
    $accountCodeClause AS ACCT_NO,
    -- @todo - not for endowment - need the number for that
    '100-WMF' as LOCATION_ID,
    $deptIDClause as DEPT_ID,
    REGEXP_SUBSTR(%1, '[0-9]+(?=_[A-Z]{3})')  as DOCUMENT,
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Donations') as MEMO,
    0 as DEBIT,
    SUM(COALESCE(settled_donation_amount, 0)) as CREDIT,
    s.settlement_currency as CURRENCY,
    %2 as EXCH_RATE_DATE,
    $restrictionsClause as GLDIMFUNDING,
    $vendorClause as GLENTRY_VENDORID
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
         LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = s.settlement_batch_reference)
  AND (COALESCE(settled_donation_amount, 0) <> 0)
  AND is_template = 0
GROUP BY Fund, gift.channel, is_major_gift

UNION
  SELECT
   %2 as DATE,
   CONCAT('Contribution Revenue ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'), ' - ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y') ) as DESCRIPTION,
   $accountCodeClause AS ACCT_NO,
    -- @todo - not for endowment - need the number for that
    '100-WMF' as LOCATION_ID,
    $deptIDClause as DEPT_ID,
    REGEXP_SUBSTR(%1, '[0-9]+(?=_[A-Z]{3})')  as DOCUMENT,
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Refunds') as MEMO,

    SUM(-COALESCE(settled_reversal_amount, 0)) as DEBIT,
    0 as CREDIT,
    s.settlement_currency as CURRENCY,
    %2 as EXCH_RATE_DATE,
    $restrictionsClause as GLDIMFUNDING,
    $vendorClause as GLENTRY_VENDORID
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
         LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = s.settlement_batch_reversal_reference)
  AND (COALESCE(settled_reversal_amount, 0) <> 0)
  AND is_template = 0
GROUP BY Fund, gift.channel, is_major_gift

UNION

-- Fee transactions part.
SELECT
    %2 as DATE,
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'), ' - ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y') ) as DESCRIPTION,
    60917 as ACCT_NO,

-- @todo - not for endowment - need the number for that
    '100-WMF' as LOCATION_ID,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    REGEXP_SUBSTR(%1, '[0-9]+(?=_[A-Z]{3})')  as DOCUMENT,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Donation Fees') as MEMO,
    SUM(-COALESCE(settled_fee_amount, 0))as DEBIT,
    0 as CREDIT,
    s.settlement_currency as CURRENCY,
    DATE_FORMAT(s.settlement_date, '%m/%d/%Y') as EXCH_RATE_DATE,
    'Unrestricted' as GLDIMFUNDING,
    $vendorClause as GLENTRY_VENDORID
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reference)
  AND ( settled_fee_amount <> 0)
  AND trxn_id NOT LIKE 'adyen transaction%'
  AND trxn_id NOT LIKE 'adyen invoice%'
  AND is_template = 0
GROUP BY s.settlement_batch_reference

UNION

SELECT
    %2 as DATE,
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'), ' - ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y') ) as DESCRIPTION,
    60917 as ACCT_NO,

-- @todo - not for endowment - need the number for that
    '100-WMF' as LOCATION_ID,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    REGEXP_SUBSTR(%1, '[0-9]+(?=_[A-Z]{3})')  as DOCUMENT,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Donation Fees') as MEMO,
    -- @todo obv some cleaup in here
    SUM(-COALESCE(settled_fee_reversal_amount, 0)) as DEBIT,
    0 as CREDIT,
    s.settlement_currency as CURRENCY,
    DATE_FORMAT(s.settlement_date, '%m/%d/%Y') as EXCH_RATE_DATE,
    'Unrestricted' as GLDIMFUNDING,
    $vendorClause as GLENTRY_VENDORID
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reversal_reference)
  AND ( settled_fee_reversal_amount <> 0)
  AND (trxn_id NOT LIKE 'adyen transaction%' AND trxn_id NOT LIKE 'adyen invoice %')
  AND is_template = 0
GROUP BY s.settlement_batch_reversal_reference

UNION
-- Fee transactions part.
SELECT
    %2 as DATE,
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'), ' - ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y') ) as DESCRIPTION,
    60917 as ACCT_NO,
-- @todo - not for endowment - need the number for that
    '100-WMF' as LOCATION_ID,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    REGEXP_SUBSTR(%1, '[0-9]+(?=_[A-Z]{3})')  as DOCUMENT,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Invoice Fees') as MEMO,
    -- @todo obv some cleaup in here
    SUM(COALESCE(-settled_fee_amount, 0)) as DEBIT,
    0 as CREDIT,
    s.settlement_currency as CURRENCY,
    DATE_FORMAT(s.settlement_date, '%m/%d/%Y') as EXCH_RATE_DATE,
    'Unrestricted' as GLDIMFUNDING,
    $vendorClause as GLENTRY_VENDORID
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reference)
  AND ( settled_fee_amount <> 0)
  AND (trxn_id LIKE 'adyen transaction%' OR trxn_id LIKE 'adyen invoice %')
  AND is_template = 0
GROUP BY s.settlement_batch_reference
";

    if ($this->batchPrefix) {
      $batches = Batch::get(FALSE)
        ->addWhere('name', 'LIKE', '%' . $this->batchPrefix . '_%')
        ->addSelect('batch_data.*', '*', 'status_id:name')
        ->execute()->indexBy('name');
    }
    else {
      $batches = Batch::get(FALSE)
        ->addWhere('status_id:name', '=', 'total_verified')
        ->addSelect('batch_data.*', '*', 'status_id:name')
        ->execute()->indexBy('name');
    }

    $rowNumber = 1;

    foreach ($batches as $batch) {
      $defaults = [
        'DONOTIMPORT' => '',
        'JOURNAL' => 'CREV',
        'DATE' => date('m/d/Y', strtotime($batch['batch_data.settlement_date'])),
        'REVERSEDATE' => '',
        'REFERENCE_NO' => '',
        'LINE_NO' => '',
        'ACCT_NO' => '',
        'LOCATION_ID' => '',
        'DEPT_ID' => '',
        'DOCUMENT' => explode('_', $batch['name'])[1],
        'DEBIT' => 0,
        'CREDIT' => 0,
        'SOURCEENTITY' => '',
        'CURRENCY' => $batch['batch_data.settlement_currency'],
        'EXCH_RATE_DATE' => '',
        'EXCH_RATE_TYPE_ID' => '',
        'EXCHANGE_RATE' => '',
        'STATE' => 'Draft',
        'ALLOCATION_ID' => '',
        'BILLABLE' => '',
        'GLDIMEVENT_ID' => '',
        'GLDIMFUNDING' => '',
        'GLENTRY_PROJECTID' => '',
        'GLENTRY_CLASSID' => '',
        'GLENTRY_CUSTOMERID' => '',
        'GLENTRY_VENDORID' => '',
        'GLENTRY_ITEMID' => '',
        'GLENTRY_EMPLOYEEID' => '',
        'DESCRIPTION' => '',
        'MEMO' => '',
      ];
      /**
      $record['contributions'] = (array) Contribution::get(FALSE)
        ->addWhere('contribution_settlement.settlement_batch_reference', '=', $batch['name'])
        ->addSelect('contribution_extra.*', 'contribution_settlement.*', 'total_amount', 'fee_amount', 'net_amount', 'trxn_id', 'invoice_id', 'source', 'currency', 'financial_type_id', 'receive_date')
        ->execute();
      */
      if ($batch['status_id:name'] !== 'total_verified') {
        // @todo what should we do - return information but not export?
        // export in debug mode?
        throw new \CRM_Core_Exception('batch verified - cannot export');
      }
      $this->batchSummary[$batch['name']] = [
        'currency' => $batch['batch_data.settlement_currency'],
        'annual_fund_fees' => Money::of(0, $batch['batch_data.settlement_currency']),
        'endowment_fund_fees' => Money::of(0, $batch['batch_data.settlement_currency']),
      ];

      $renderedSql = CRM_Core_DAO::composeQuery($sql, [
        1 => [$batch['name'], 'String'],
        2 => [date('m/d/Y', strtotime($batch['batch_data.settlement_date'])), 'String']]
      );
      $batchedData = CRM_Core_DAO::executeQuery($renderedSql)->fetchAll();
      $this->setHeaders($defaults);
      $record = [
        'csv_rows' => $batchedData,
        'batch' => $batch,
      ];
      if ($this->isOutputSQL) {
        $record['sql'] = $renderedSql;
      }
      $debit = $credit = $feeDebit = $feeCredit = Money::of(0, $batch['batch_data.settlement_currency']);
      $count = 0;
      foreach ($record['csv_rows'] as $index => $row) {
        $record['csv_rows'][$index] = [...$defaults, ...$row];
        $record['csv_rows'][$index]['LINE_NO'] = $rowNumber;
        $rowNumber+= 2;
        $isFee = $this->isFee($row);
        $rowCount = (int)(explode(' | ', $row['MEMO'])[4]);
        if (!$isFee) {
          $count += $rowCount;
        }
        if (str_ends_with($row['MEMO'], 'Fees')
        && !str_ends_with($row['MEMO'], 'Donation Fees')
        ) {
          // These are the fee-only rows. Include them in the count
          // as they are in the batch count...
          $count += $rowCount;
        }
        if ($isFee) {
          $feeDebit = $feeDebit->plus($row['DEBIT'] ?: 0);
          $feeCredit = $feeCredit->plus($row['CREDIT'] ?: 0);
        }
        else {
          $debit = $debit->plus($row['DEBIT'] ?: 0);
          $credit = $credit->plus($row['CREDIT'] ?: 0);
        }
      }

      $record['totals'] = [
        'debit' => (string) $debit->getAmount(),
        'credit' => (string) $credit->getAmount(),
        'fee_debit' => (string) $feeDebit->getAmount(),
        'fee_credit' => (string) $feeCredit->getAmount(),
        'fee' => (string) $feeDebit->minus($feeCredit)->getAmount(),
        'settled' => (string) $credit->minus($debit)->minus($feeDebit)->plus($feeCredit)->getAmount(),
        'count' => $count
      ];
      $record['expected'] = [
        'count' => $batch['item_count'],
        'credit' => round($batch['batch_data.settled_donation_amount'], 2),
        'debit' => round($batch['batch_data.settled_reversal_amount'], 2),
        'fee' => $batch['batch_data.settled_fee_amount'],
        'settled' => round($batch['batch_data.settled_net_amount'], 2),
      ];
      $record['validation'] = [
        'count' => $record['expected']['count'] - $record['totals']['count'],
        'credit' => $record['expected']['credit'] - $record['totals']['credit'],
        'debit' =>  $record['expected']['debit'] + $record['totals']['debit'],
        'fee' => $record['expected']['fee'] + $record['totals']['fee'],
        'settled' => $record['expected']['settled'] - $record['totals']['settled'],
      ];
      $this->addToCsv($this->getRowsWithReversals($record['csv_rows']), $renderedSql, $batch['name']);
      $isValid = empty(array_filter($record['validation']));
      $this->batchSummary[$batch['name']]['is_valid'] = $isValid;
      $this->log($batch['name'] . ' ' . ($isValid ? 'has valid totals' : ' has a discrepancy '));
      if (!$isValid) {
        foreach (array_filter($record['validation']) as $key => $value) {
          if ($key !== 'count') {
            $value = \Civi::format()->money($value, $this->batchSummary[$batch['name']]['currency']);
          }
          $this->log($key . " has discrepancy of $value (expected {$record['expected'][$key]}, actual {$record['totals'][$key]} )");
        }
      }
      if (!$this->isOutputRows) {
        unset($record['csv_rows']);
      }
      $result[] = $record;
    }

    // Do not close any batches unless all verified batches are valid. We will make sure they
    // are right before closing.
    $draftFileName = $this->getDraftFileName();
    if (empty($this->getInvalidBatches()) && empty($this->incompleteRows)) {
      Batch::update(FALSE)
        ->addValue('status_id:name', 'validated')
        ->addWhere('id', 'IN', array_keys($this->batchSummary))
        ->execute();
      $this->log('The following batches have been validated and closed ' . implode(',', array_keys($this->batchSummary)));
      if ($draftFileName) {
        $finalFileName = str_replace('-draft', '-final', $draftFileName);
        rename($draftFileName, $finalFileName);
        $this->log('final file name ' . $finalFileName);
      }
      else {
        $this->log('no file generated due to input parameters');
      }
    }
    else {
      if (!empty($this->incompleteRows)) {
        $this->log('No batches closed due to presence of rows without account codes ' . implode(',', $this->getInvalidBatches()));
      }
      if ($this->getInvalidBatches()) {
        $this->log('No batches closed due to presence of invalid batches ' . implode(',', $this->getInvalidBatches()));
      }
      if ($draftFileName) {
        $this->log('draft file location ' . $draftFileName);
      }
    }
    $this->log('Account code logic ' . $this->getAccountClause());
    $this->sendSummary($result, $finalFileName ?? NULL);
  }

  public static function fields(): array {
    return [];
  }

  /**
   * @param $csv_rows
   * @return void
   */
  public function addToCsv($csv_rows, $renderedSql, string $batchName): void {
    if ($this->isOutputCsv) {
      $writer = $this->getWriter();
      $writer->insertAll($csv_rows);
      $batchJournalWriter = $this->getBatchJournalWriter($batchName);
      $batchJournalWriter->insertAll($csv_rows);
      $detailedData = $this->getDetailData($renderedSql);
      foreach ($detailedData as $row) {
        if (empty($row['ACCT_NO'])) {
          $this->incompleteRows[] = $row;
          $this->log("Account number not found for id {$row['contribution_id']} in channel . {$row['channel']}");
        }
        else {
          if (empty($this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']])) {
            $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']] = [
              'annual_fund' => Money::of(0, $this->batchSummary[$batchName]['currency']),
              'endowment_fund' => Money::of(0, $this->batchSummary[$batchName]['currency']),
            ];
          }
          $fund = $row['is_endowment'] ? 'endowment_fund' : 'annual_fund';
          if ($this->isFee($row)) {
            $fee = $this->batchSummary[$batchName][$fund . '_fees'];
            $fee = $fee->plus($row['settled_fee_amount'] ?: 0);
            $fee = $fee->plus($row['settled_reversal_fee_amount'] ?: 0);
            $this->batchSummary[$batchName][$fund . '_fees'] = $fee;
          }
          else {
            $amount = $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']][$fund];
            $amount = $amount->plus($row['settled_donation_amount'] ?: 0);
            $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']][$fund] = $amount;
          }
        }
      }
      $detailWriter = $this->getDetailsWriter(array_keys($detailedData[0] ?? []), $batchName);
      $detailWriter->insertAll($detailedData);
    }
  }

  /**
   * @return Writer|null
   */
  public function getWriter(): Writer {
    if (!isset($this->writer)) {
      $this->writer = Writer::createFromPath(\Civi::settings()->get('wmf_audit_intact_files') . '/' . date('Y-m-d H:i:s') . $this->batchPrefix . '-draft.csv', 'w');
      $this->writer->insertOne($this->headers);
    }
    return $this->writer;
  }

  /**
   * @return Writer
   */
  public function getDetailsWriter(array $headers, $batchName): Writer {
    if (!isset($this->detailWriters[$batchName])) {
      $this->detailWriters[$batchName] = Writer::createFromPath(\Civi::settings()->get('wmf_audit_intact_files') . '/' . $batchName . '_details.csv', 'w');
      $this->detailWriters[$batchName]->insertOne($headers);
    }
    return $this->detailWriters[$batchName];
  }

  /**
   * @return Writer
   */
  private function getBatchJournalWriter(string $batchName): Writer {
    if (!isset($this->journalWriters[$batchName])) {
      $this->journalWriters[$batchName] = Writer::createFromPath(\Civi::settings()->get('wmf_audit_intact_files') . '/' . $batchName . '_journals.csv', 'w');
      $this->journalWriters[$batchName]->insertOne($this->headers);
    }
    return $this->journalWriters[$batchName];
  }

  /**
   * @param array $defaults)
   * @return void
   */
  public function setHeaders(array $defaults): void {
    if (empty($this->headers)) {
      $this->headers = array_keys($defaults);
    }
  }

  private function getAccountName($accountCode): string {
    $map = [
      43480 => 'Recurring Gift',
      43481 => 'Banner',
      43482 => 'Email',
      43483 => 'Direct Mail',
      43484 => 'Online Other',
      43440 => 'Chapter Gifts',
      43485 => 'Major Gifts - Unrestricted',
      43428 => 'Major Gifts - Restricted',
    ];
    return $map[$accountCode] ?? '';
  }
  /**
   * @return string
   */
  public function getAccountClause(): string {
    return "
    -- See GL Account Structure Rules
    -- Online Recurring Contributions 43480 = channel =  	Recurring Gift
    -- Online Banner Contributions	43481 = channel = Banner
    -- Online Email Contributions	43482 = channel = email
    -- Online Direct Mail Contributions	43483 = channel = Direct Mail
    -- Online Other Contributions	43484
    -- Chapter Gifts	43440 = channel =  Chapter Gifts
    -- Major Gifts - Unrestricted	43485
    -- Major Gifts - Restricted	43428
    CASE
  -- 1) Major gifts first
  WHEN gift.is_major_gift = 1 AND gift.fund LIKE 'restricted%' THEN 43428  -- Major Gifts - Restricted
  WHEN gift.is_major_gift = 1 THEN 43485                                   -- Major Gifts - Unrestricted

  -- 2) Specific channels
  WHEN gift.channel = 'Chapter Gifts'   THEN 43440   -- Chapter Gifts
  WHEN gift.channel = 'Recurring Gift'  THEN 43480   -- Online Recurring Contributions
  WHEN gift.channel = 'Mobile Banner'   THEN 43481   -- Online Banner Contributions
  WHEN gift.channel = 'Desktop Banner'  THEN 43481   -- Online Banner Contributions
  WHEN gift.channel = 'Other Banner'    THEN 43481   -- Online Banner Contributions
  WHEN gift.channel = 'Email'           THEN 43482   -- Online Email Contributions
  WHEN gift.channel = 'Direct Mail'     THEN 43483   -- Online Direct Mail Contributions
  WHEN gift.channel = 'SMS'             THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Other Online'    THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Portal Banner'   THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Sidebar'         THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Wikipedia App'   THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Wikimedia Portal' THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Other Portal'    THEN 43484   -- Other Online Contributions
  WHEN gift.channel = 'Social Media'    THEN 43484   -- Other Online Contributions
  -- 3) Everything else -> Online Other Contributions
  -- remaining = 'Workplace Giving','Direct Solicitation','Planned Giving', 'Events','White Mail','Other Offline'
  ELSE '' -- default/fallback - this will cause the batch to report errors and not close.
END";
  }

  /**
   * @return string
   */
  public function getDeptIDClause(): string {
    return "-- cost centre
-- only use if Major Gifts - CC104 else  Online Fundraising - CC-1005
-- @todo
    IF(is_major_gift, 'CC-1004', 'CC-1005')";
  }

  /**
   * @return string
   */
  public function getRestrictionsClause(): string {
    return "-- @todo - this gets restrictions for major gifts transactions.
    IF('is_major_gift' AND Fund LIKE 'Restricted%', 'Temporarily Restricted', 'Unrestricted')";
  }

  /**
   * @return string
   */
  public function getVendorClause(): string {
    return "-- Can be a case statement when we have more vendors - this one is adyen.
    'V01670'";
  }

  /**
   * @param string $renderedSql
   * @return array
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function getDetailData(string $renderedSql): array {
    $detailSQL = str_replace('GROUP BY ', 'GROUP BY c.id, ', $renderedSql);
    $detailSQL = str_replace('GLENTRY_VENDORID', 'GLENTRY_VENDORID,
        c.id as contribution_id, gift.channel, gift.fund, gift.is_major_gift,
        IF(c.financial_type_id = 26, 1, 0) as is_endowment,
        x.gateway,
        x.gateway_txn_id,
        x.backend_processor,
        x.backend_processor_txn_id,
        x.payment_orchestrator_reconciliation_id,
        x.original_amount,
        x.original_currency,
        settled_donation_amount,
        settled_fee_amount,
        settled_reversal_amount
        settled_reversal_fee_amount ',
      $detailSQL
    );
    $detailSQL = str_replace(
      ' LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id',
      ' LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
        LEFT JOIN wmf_contribution_extra x ON c.id = x.entity_id',
      $detailSQL
    );
    return CRM_Core_DAO::executeQuery($detailSQL)->fetchAll();
  }

  /**
   * Get the csv output rows augmented with reversal rows.
   *
   * The reversals code to 11250 so debits & credits wind up being equal.
   *
   * @param array $csvRows
   * @return array
   */
  public function getRowsWithReversals(array $csvRows): array {
    $rowsWithReversals = [];
    foreach ($csvRows as $row) {
      $rowsWithReversals[] = $row;
      $reversalRow = $row;
      $reversalRow['DEBIT'] = $row['CREDIT'];
      $reversalRow['CREDIT'] = $row['DEBIT'];
      $reversalRow['LINE_NO'] = $row['LINE_NO'] + 1;
      $reversalRow['ACCT_NO'] = 11250;
      $memoParts = explode(' | ', $row['MEMO']);
      unset($memoParts[4], $memoParts[5], $memoParts[6]);
      $reversalRow['MEMO'] = implode(' | ', $memoParts);
      $rowsWithReversals[] = $reversalRow;
    }
    return $rowsWithReversals;
  }

  /**
   * @param Result $result
   * @return void
   */
  public function sendSummary(Result $result, ?string $fileName): void {
    if (count($result)) {
      if ($this->emailSummaryAddress) {
        $params = [
          'toEmail' => $this->emailSummaryAddress,
          'subject' => 'Finance batch generated',
          'from' => \CRM_Core_BAO_Domain::getFromEmail(),
        ];

        $invalidBatches = 0;

        $tableOpenHtml = '<table style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 14px;">';
        // Start styled table.
        $html = '<html> <h3>The following batches have been generated</h3>';
        if (empty($this->getInvalidBatches()) && empty($this->incompleteRows)) {
          $html .= '<p>All batches have validated and the batches have been closed. The journal file is attached</p>';
          $minDate = NULL;
          $maxDate = NULL;
          foreach ($result as $batch) {
            $date = $batch['batch']['batch_data.settlement_date'];
            $minDate = $minDate && strtotime($date) > strtotime($minDate) ? $minDate : $date;
            $maxDate = $maxDate && strtotime($date) > strtotime($maxDate) ? $maxDate : $date;
          }
          $params['attachments'] = [[
            'fullPath' => $fileName,
            'cleanName' => "{$minDate} to {$maxDate} Wikimedia Foundation Online Contribution Revenue.csv",
            'mime_type' => 'text/plain',
          ]];
        }
        else {
          $html .= "<p>One of more errors have prevented the batch from being closed (hence no file is attached).</p>";
        }
        $html .= $tableOpenHtml . '
          <thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Batch</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Settled Currency</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Settled Total (In Bank)</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Total in batch (From CiviCRM)</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Discrepancy</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Files</th>
            </tr>
          </thead>
          <tbody>
      ';

        foreach ($result as $batch) {
          if (!empty(array_filter($batch['validation']))) {
            $invalidBatches++;
          }
          $currency  = htmlspecialchars($batch['batch']['batch_data.settlement_currency'], ENT_QUOTES, 'UTF-8', $batch['batch']['batch_data.settlement_currency']);
          $discrepancy = $batch['validation']['settled'];

          // Base cell styles
          $cell = 'border: 1px solid #ccc; padding: 6px;';
          $cellRight = $cell . ' text-align: right;';

          // Discrepancy cell: red + bold when non-zero
          $discrepancyStyle = $cellRight;
          if ((float) $discrepancy !== 0.0) {
            $discrepancyStyle .= ' color: #b30000; font-weight: bold;';
          }
          $discrepancy = \Civi::format()->money($discrepancy, $currency);

          $batchName = htmlspecialchars($batch['batch']['name'], ENT_QUOTES, 'UTF-8');
          // This url will probably iterate - I have some ideas - but for now...
          $filerName = 'contribution_settlement.settlement_batch_reference,contribution_settlement.settlement_batch_reversal_reference';
          $batchUrl = (string) \Civi::url('backend://civicrm/contribution/settled#?' . $filerName . '=' . $batch['batch']['name'], 'a');

          $settled   = \Civi::format()->money(htmlspecialchars($batch['totals']['settled'], ENT_QUOTES, 'UTF-8'), $currency);
          $totalInBatch = \Civi::format()->money(htmlspecialchars($batch['batch']['batch_data.settled_net_amount'], ENT_QUOTES, 'UTF-8'), $currency);
          $numberOfTransactions = $batch['batch']['total'];
          $transactionsUrl = BatchFile::getBatchFileUrl([$batchName], 'details');
          $journalUrl = BatchFile::getBatchFileUrl([$batchName], 'journals');
          $html .= "
          <tr>
            <td style=\"$cell\"><a href='{$batchUrl}'>{$batchName}</a></td>
            <td style=\"$cell\">{$currency}</td>
            <td style=\"$cellRight\">{$totalInBatch}</td>
            <td style=\"$cellRight\">{$settled}</td>
            <td style=\"$discrepancyStyle\">{$discrepancy}</td>
            <td style=\"$cellRight\">" . ($this->isOutputCsv && $numberOfTransactions ? "<a href='{$transactionsUrl}'> Download Transactions</a>" . "<br><a href='{$journalUrl}'> Download Journals</a>" : '') . "</td>
          </tr>
        ";
        }
        $html .= "
          </tbody>
        </table>";

        if ($this->incompleteRows) {
          $html .= "<h3>Found contribution/s without Account code</h3><p>The summary table below will not add up to the total due to these</p>" . $tableOpenHtml . '
          <thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Contribution</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Channel</th>
            </tr>
          </thead>
          <tbody>';
          foreach ($this->incompleteRows as $row) {
            $contributionURL = \CRM_Utils_System::url('civicrm/contact/view/contribution',[
              'id' => $row['contribution_id'],
              'reset' => 1,
            ], TRUE);
            $html .= "
          <tr>
            <td style=\"$cell\"><a href='{$contributionURL}'>{$row['contribution_id']}</a></td>
            <td style=\"$cell\">{$row['channel']}</td>
          </tr>
        ";
          }
          $html .= " </tbody> </table>";
        }
        $html .= '<h3>Batch Summary</h3>' . $tableOpenHtml;
        $html .= '<thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Batch</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Account Code</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Account</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Is Endowment</th>
              <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Total</th>
            </tr>
          </thead>
          <tbody>';
        foreach ($this->batchSummary as $batchName => $batch) {
          $start = "<tr><td>{$batchName}</td>";
          if (!$batch['annual_fund_fees']->isEqualTo(0)) {
            $amount = (string) $batch['annual_fund_fees'];
            $html.= $start . "<td>60917</td><td>Fees</td><td>No</td><td>{$amount}</td></tr>";
          }

          if (!$batch['endowment_fund_fees']->isEqualTo(0)) {
            $amount = (string) $batch['endowment_fund_fees'];
            $html.= $start . "<td>60917</td><td>Fees</td><td>Yes</td><td>{$amount}</td></tr>";
          }
          foreach ($batch['accounts'] as $accountNumber => $account) {
            $accountName = $this->getAccountName($accountNumber);
            if (!$account['annual_fund']->isEqualTo(0)) {
              $amount = (string) $account['annual_fund'];
              $html.= $start . "<td>{$accountNumber}</td><td>{$accountName}</td><td>No</td><td>{$amount}</td></tr>";
            }
            if (!$account['endowment_fund']->isEqualTo(0)) {
              $amount = (string) $account['endowment_fund'];
              $html.= $start . "<td>{$accountNumber}</td><td>{$accountName}</td><td>No</td><td>{$amount}</td></tr>";
            }
          }
        }
        $html.= '</tbody></table>';
        $html .= '<h3>Log</h3> ' . $tableOpenHtml;
        foreach ($this->log as $log) {
          $html .= "<tr><td>$log</td></tr>";
        }

        $html .= '</table></html>';

        if ($invalidBatches) {
          $params['subject'] .= " {$invalidBatches} need attention";
        }
        if ($this->incompleteRows) {
          $params['subject'] .= " " . count($this->incompleteRows) . " contributions need attention";
        }

        $params['html'] = $html;

        if (!\CRM_Utils_Mail::send($params)) {
          \Civi::log('wmf')->warning('Summary failed to send to ' . $params['toEmail']);
        }
      }
    }
  }

  private function log(string $string): void {
    $this->log[] = date('Y-m-d-m-Y-H-i-s') . ' ' . $string;
    \Civi::log('finance_integration')->info($string);
  }

  /**
   * Get batches that did not validated.
   * @return array
   */
  private function getInvalidBatches(): array {
    $invalidBatches = [];
    foreach ($this->batchSummary as $batchName => $batch) {
      if (!$batch['is_valid']) {
        $invalidBatches[] = $batchName;
      }
    }
    return $invalidBatches;
  }

  /**
   * @param $MEMO
   * @return bool
   */
  public function isFee($row): bool {
    return str_contains($row['MEMO'], 'Fee');
  }

  /**
   * @return string
   */
  private function getDraftFileName(): string {
    if (!isset($this->writer)) {
      return '';
    }
    return $this->writer->getPathname();
  }

}

<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
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

  private array $headers = [];

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
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | ', DATE_FORMAT(MIN(receive_date), '%m/%d/%Y'),' | ' , DATE_FORMAT(MAX(receive_date), '%m/%d/%Y'), ' | ', COUNT(*), ' | ', ' Fees') as MEMO,
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
        'JOURNAL' => 'crev',
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
        $isFee = str_contains($row['MEMO'], 'Fee');
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
       $record['validation'] = [
        'count' => $batch['item_count'] - $record['totals']['count'],
        'credit' => round($batch['batch_data.settled_donation_amount'], 2) - $record['totals']['credit'],
        'debit' => round($batch['batch_data.settled_reversal_amount'], 2) + $record['totals']['debit'],
        'fee' => $batch['batch_data.settled_fee_amount'] + $record['totals']['fee'],
        'settled' => round($batch['batch_data.settled_net_amount'], 2) - $record['totals']['settled'],
      ];
      $this->addToCsv($this->getRowsWithReversals($record['csv_rows']), $renderedSql, $batch['batch_data.settlement_currency']);
      if (!$this->isOutputRows) {
        unset($record['csv_rows']);
      }
      $result[] = $record;
    }
    $this->sendSummary($result);
  }

  public static function fields(): array {
    return [];
  }

  /**
   * @param $csv_rows
   * @return void
   */
  public function addToCsv($csv_rows, $renderedSql, string $settledCurrency): void {
    if ($this->isOutputCsv) {
      $writer = $this->getWriter();
      $writer->insertAll($csv_rows);
      $detailedData = $this->getDetailData($renderedSql);
      $detailWriter = $this->getDetailsWriter(array_keys($detailedData[0]), $settledCurrency);
      $detailWriter->insertAll($detailedData);
    }
  }

  /**
   * @return Writer|null
   */
  public function getWriter(): Writer {
    if (!isset($this->writer)) {
      $this->writer = Writer::createFromPath(\Civi::settings()->get('wmf_audit_intact_files') . '/' . $this->batchPrefix . '.csv', 'w');
      $this->writer->insertOne($this->headers);
    }
    return $this->writer;
  }

  /**
   * @return Writer
   */
  public function getDetailsWriter(array $headers, $currency): Writer {
    if (!isset($this->detailWriters[$currency])) {
      $this->detailWriters[$currency] = Writer::createFromPath(\Civi::settings()->get('wmf_audit_intact_files') . '/' . $this->batchPrefix . '_' . $currency . '_details.csv', 'w');
      $this->detailWriters[$currency]->insertOne($headers);
    }
    return $this->detailWriters[$currency];
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
    -- @todo concat in Major gifts, get source data correct for these
    -- will have to do case statement but hopefully on just 2 fields
    CASE
  -- 1) Major gifts first
  WHEN gift.is_major_gift = 1 AND gift.fund LIKE 'restricted%' THEN 43428  -- Major Gifts - Restricted
  WHEN gift.is_major_gift = 1 THEN 43485                                          -- Major Gifts - Unrestricted

  -- 2) Specific channels
  WHEN gift.channel = 'Chapter Gifts'   THEN 43440   -- Chapter Gifts
  WHEN gift.channel = 'Recurring Gift'  THEN 43480   -- Online Recurring Contributions
  WHEN gift.channel = 'Mobile Banner'   THEN 43481   -- Online Banner Contributions
  WHEN gift.channel = 'Desktop Banner'   THEN 43481   -- Online Banner Contributions
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
  ELSE '' -- default/fallback
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
  public function sendSummary(Result $result): void {
    if (count($result)) {
      if ($this->emailSummaryAddress) {
        $params = ['toEmail' => $this->emailSummaryAddress, 'subject' => 'Finance batch generated', 'from' => \CRM_Core_BAO_Domain::getFromEmail()];
        $invalidBatches = 0;
        $html = "<table><th>Batch</th><th>Settled Currency</th><th>Settled Total</th><th>Total in batch</th><th>Discrepancy</th>";
        foreach ($result as $batch) {
          if (!empty(array_filter($batch['validation']))) {
            $invalidBatches++;
          }
          $discrepancy = $batch['validation']['credit'] + $batch['validation']['debit'] + $batch['validation']['fee'];
          $html .= "<tr><td>{$batch['batch']['name']}</td><td>{$batch['batch']['batch_data.settlement_currency']}</td><td>{$batch['totals']['settled']}</td><td></td><td>$discrepancy</td></tr>";
        }
        if ($invalidBatches) {
          $params['subject'] .= " $invalidBatches need attention";
        }
        $html .= "</table>";
        $params['html'] = $html;
        if (!\CRM_Utils_Mail::send($params)) {
          \Civi::log('wmf')->warning('Summary failed to send to ' . $params['toEmail']);
        }
      }
    }
  }

}

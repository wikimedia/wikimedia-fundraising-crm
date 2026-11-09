<?php

namespace Civi\WMFBatch;

use Brick\Money\Money;
use Civi\Api4\GatewayAccount;
use CRM_Core_DAO;

/**
 * Builds the journal rows for one settlement batch: runs the big UNION ALL
 * query (donations/refunds/fees/fee-reversals/invoice-fees/adjustments
 * sharing one settlement batch reference) and the account/dept/restriction
 * routing rules that feed it.
 *
 * Moved here from WMFAudit.GenerateBatch so the upcoming Batch.generateJournals
 * /validate actions can reuse it without going through that action. The
 * gateway-account lookups at the bottom are duplicated from GenerateBatch,
 * which still needs its own copies for the parts not yet extracted.
 */
class JournalGenerator {

  /**
   * The batch to generate journals for.
   *
   * @var array
   */
  private array $batch;

  public function __construct(array $batch) {
    $this->batch = $batch;
  }

  /**
   * @return array{rows: array, batchSummary: array, defaults: array, sql: string}
   *   'rows' are the csv rows; 'batchSummary' seeds the caller's per-batch
   *   currency/fee totals; 'defaults' is the row shape (for setHeaders());
   *   'sql' is the rendered query (for isOutputSql reporting).
   *
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function getJournalRows(): array {
    $isEndowmentGateway = $this->isEndowmentGateway();
    // Note that not all of these fields are used. They are part of the finance spec but
    // when we push journals to Accounts we only use a subset. We could probably stop populating them.
    // Just a bit nervous to alter at this point.
    $defaults = [
      'DONOTIMPORT' => '',
      'JOURNAL' => 'CREV',
      'DATE' => date('m/d/Y', strtotime($this->batch['batch_data.settlement_date'])),
      'REVERSEDATE' => '',
      'REFERENCE_NO' => '',
      'LINE_NO' => '',
      'ACCT_NO' => '',
      'LOCATION_ID' => $isEndowmentGateway ? FinanceConstants::LOCATION_ID_ENDOWMENT : FinanceConstants::LOCATION_ID_WMF,
      'DEPT_ID' => '',
      'DOCUMENT' => $this->batch['name'],
      'DEBIT' => 0,
      'CREDIT' => 0,
      'SOURCEENTITY' => '',
      'CURRENCY' => $this->batch['batch_data.settlement_currency'],
      'EXCH_RATE_DATE' => date('m/d/Y', strtotime($this->batch['batch_data.settlement_date'])),
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
      'GLENTRY_VENDORID' => $this->getVendorCode(),
      'GLENTRY_ITEMID' => '',
      'GLENTRY_EMPLOYEEID' => '',
      'DESCRIPTION' => '',
      'MEMO' => '',
    ];

    $batchSummary = [
      'currency' => $this->batch['batch_data.settlement_currency'],
      'annual_fund_fees' => Money::zero($this->batch['batch_data.settlement_currency']),
      'endowment_fund_fees' => Money::zero($this->batch['batch_data.settlement_currency']),
      'is_endowment_gateway' => $isEndowmentGateway,
    ];
    $renderedSql = $this->getRenderedSql();
    $batchedData = CRM_Core_DAO::executeQuery($renderedSql)->fetchAll();

    $rowNumber = 1;
    foreach ($batchedData as $index => $row) {
      $batchedData[$index] = [...$defaults, ...$row];
      // This requirement for adding contiguous line numbers is likely defunct.
      // It was part of the SFTP csv file upload spec and originally multiple
      // batches were in one file so the line number was per file, not per batch.
      // When using the api upload line no is not used. However, it might be
      // that finance still wish to see it in output file so retaining for now.
      $batchedData[$index]['LINE_NO'] = $rowNumber;
      $rowNumber += 2;
    }
    return [
      'rows' => $batchedData,
      'batchSummary' => $batchSummary,
      'defaults' => $defaults,
      'sql' => $renderedSql,
    ];
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getRenderedSql(): string {
    $sql = $this->getBatchSql();
    $renderedSql = CRM_Core_DAO::composeQuery($sql, [
      1 => [$this->batch['name'], 'String'],
    ]);
    return $renderedSql;
  }

  /**
   * @param string $renderedSql
   * @return array
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function getDetailData(string $renderedSql): array {
    $detailSQL = str_replace('GROUP BY ', 'GROUP BY c.id, ', $renderedSql);
    $endowmentFinancialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    $locationID = $this->isEndowmentGateway() ? FinanceConstants::LOCATION_ID_ENDOWMENT : FinanceConstants::LOCATION_ID_WMF;
    $detailSQL = str_replace('GLDIMFUNDING', 'GLDIMFUNDING,
        "' . $locationID . '" as LOCATION_ID,
        "' . $this->getVendorCode() . '" as GL_VENDOR,
        IF(s.settlement_batch_reference = "' . $this->batch['name'] . '", s.settlement_date, "' . $this->batch['batch_data.settlement_date'] . ' 00:00:00") as settlement_date,
        c.id as contribution_id,
        c.invoice_id as order_id,
        t.id as contribution_tracking_id,
        gift.channel,
        gift.fund,
        gift.is_major_gift,
        IF(c.financial_type_id = ' . $endowmentFinancialType . ', 1, 0) as is_endowment,
        "' . $this->getGateway() . '" as gateway,
        COALESCE(x.backend_processor, x.gateway) as backend_processor,
        COALESCE(x.backend_processor_txn_id, x.gateway_txn_id) as backend_processor_txn_id,
        IF(x.gateway = "gravy", "gravy", "") as payment_orchestrator,
        x.payment_orchestrator_reconciliation_id,
        x.original_amount,
        x.original_currency,
        settled_donation_amount,
        settled_fee_amount,
        settled_reversal_amount,
        settled_fee_reversal_amount ',
      $detailSQL
    );
    $detailSQL = str_replace(
      ' LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id',
      ' LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
        LEFT JOIN wmf_contribution_extra x ON c.id = x.entity_id
        LEFT JOIN civicrm_contribution_tracking t ON t.contribution_id = c.id
        ',
      $detailSQL
    );
    $details = CRM_Core_DAO::executeQuery($detailSQL)->fetchAll();
    return $details;
  }

  /**
   * @return string
   */
  private function getBatchSql(): string {
    $accountCodeClause = $this->getGLCodeClause();
    $deptIDClause = $this->getDeptIDClause();
    $restrictionsClause = $this->getRestrictionsClause();
    $dateDescription = $this->batch['date_description'];
    $gatewayLevelTrxnExcludeClause = ' AND ' . $this->getGatewayLevelTransactionExcludeClause() . ' AND ' . $this->getGatewayLevelTransactionExcludeAdjustmentClause();
    $gatewayLevelTrxnIncludeClause = ' AND ' . $this->getGatewayLevelTrxnIncludeClause();
    $endowmentFinancialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    $feeAccountCodeClause = $this->getFeeAccountCode();

    return "SELECT
    CONCAT('Contribution Revenue ', '{$dateDescription}') as DESCRIPTION,
    $accountCodeClause AS ACCT_NO,
    $deptIDClause as DEPT_ID,
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Donations', IF(c.financial_type_id = $endowmentFinancialType, ' " . FinanceConstants::GL_ENDOWMENT_MEMO_SUFFIX . "', '')) as MEMO,
    IF(SUM(COALESCE(settled_donation_amount, 0)) >= 0, 0, -SUM(COALESCE(settled_donation_amount, 0)))  as DEBIT,
    IF(SUM(COALESCE(settled_donation_amount, 0)) >= 0, SUM(COALESCE(settled_donation_amount, 0)), 0) as CREDIT,
    s.settlement_currency as CURRENCY,
    '' as GLENTRY_PROJECTID,
    IF(c.financial_type_id = $endowmentFinancialType , 1, 0) as is_endowment,
    $restrictionsClause as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
         LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = s.settlement_batch_reference)
  AND (COALESCE(settled_donation_amount, 0) <> 0)
  AND is_template = 0
GROUP BY Fund, $accountCodeClause, is_major_gift, CASE WHEN c.financial_type_id = $endowmentFinancialType THEN 1 ELSE 0 END

UNION ALL
  SELECT
   CONCAT('Contribution Revenue ', '{$dateDescription}' ) as DESCRIPTION,
   $accountCodeClause AS ACCT_NO,
    $deptIDClause as DEPT_ID,
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Refunds', IF(c.financial_type_id = $endowmentFinancialType, '" . FinanceConstants::GL_ENDOWMENT_MEMO_SUFFIX . "', '')) as MEMO,

    IF (SUM(-COALESCE(settled_reversal_amount, 0)) >=0, SUM(-COALESCE(settled_reversal_amount, 0)),0) as DEBIT,
    IF (SUM(-COALESCE(settled_reversal_amount, 0)) >=0, 0, SUM(-COALESCE(settled_reversal_amount, 0)))  as CREDIT,
    s.settlement_currency as CURRENCY,
    '' as GLENTRY_PROJECTID,
    IF(c.financial_type_id = $endowmentFinancialType , 1, 0) as is_endowment,
    $restrictionsClause as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
         LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = s.settlement_batch_reversal_reference)
  AND (COALESCE(settled_reversal_amount, 0) <> 0)
  AND is_template = 0
GROUP BY Fund, $accountCodeClause, is_major_gift, CASE WHEN c.financial_type_id = $endowmentFinancialType THEN 1 ELSE 0 END

UNION ALL

-- Fee transactions part.
SELECT
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', '{$dateDescription}' ) as DESCRIPTION,
    $feeAccountCodeClause as ACCT_NO,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Donation Fees') as MEMO,
    IF (SUM(-COALESCE(settled_fee_amount, 0)) >= 0, SUM(-COALESCE(settled_fee_amount, 0)), 0) as DEBIT,
    IF (SUM(-COALESCE(settled_fee_amount, 0)) >= 0, 0, SUM(COALESCE(settled_fee_amount, 0))) as CREDIT,
    s.settlement_currency as CURRENCY,
    IF(c.financial_type_id =  $endowmentFinancialType , 'FR-EI-EN', '') as GLENTRY_PROJECTID,
    IF(c.financial_type_id =  $endowmentFinancialType , 1, 0) as is_endowment,
    'Unrestricted' as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reference)
  AND ( settled_fee_amount <> 0)
  $gatewayLevelTrxnExcludeClause
  AND is_template = 0
GROUP BY s.settlement_batch_reference, CASE WHEN c.financial_type_id = $endowmentFinancialType THEN 1 ELSE 0 END

UNION ALL

SELECT
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', '{$dateDescription}') as DESCRIPTION,
    $feeAccountCodeClause as ACCT_NO,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Donation Fees') as MEMO,
    -- @todo obv some cleaup in here
    IF(SUM(-COALESCE(settled_fee_reversal_amount, 0)) >= 0, SUM(-COALESCE(settled_fee_reversal_amount, 0)), 0) as DEBIT,
    IF(SUM(-COALESCE(settled_fee_reversal_amount, 0)) >= 0, 0, SUM(COALESCE(settled_fee_reversal_amount, 0)))  as CREDIT,
    s.settlement_currency as CURRENCY,
    IF(c.financial_type_id =  $endowmentFinancialType , 'FR-EI-EN', '') as GLENTRY_PROJECTID,
    IF(c.financial_type_id =  $endowmentFinancialType , 1, 0) as is_endowment,
    'Unrestricted' as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reversal_reference)
  AND ( settled_fee_reversal_amount <> 0)
  $gatewayLevelTrxnExcludeClause
  AND is_template = 0
GROUP BY s.settlement_batch_reversal_reference, CASE WHEN c.financial_type_id = $endowmentFinancialType THEN 1 ELSE 0 END

UNION ALL
-- Fee transactions part.
SELECT
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', '{$dateDescription}' ) as DESCRIPTION,
    60917 as ACCT_NO,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Invoice Fees') as MEMO,
    -- @todo obv some cleaup in here
    IF(SUM(COALESCE(-settled_fee_amount, 0)) >= 0, SUM(COALESCE(-settled_fee_amount, 0)),0) as DEBIT,
    IF(SUM(COALESCE(-settled_fee_amount, 0)) >= 0, 0, SUM(COALESCE(settled_fee_amount, 0)))  as CREDIT,
    s.settlement_currency as CURRENCY,
    '' as GLENTRY_PROJECTID,
    0 as is_endowment,
    'Unrestricted' as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reference)
  AND ( settled_fee_amount <> 0)
  $gatewayLevelTrxnIncludeClause
  AND is_template = 0
GROUP BY s.settlement_batch_reference
UNION ALL
-- Adjustments part.
-- Deposit Corrections GL code 43495.
-- dept_id based on fees until advised otherwise
SELECT
-- note GROUP BY here....
    CONCAT('Contribution Revenue ', '{$dateDescription}' ) as DESCRIPTION,
    43495 as ACCT_NO,
-- cost centre - CC-1014 for all fees
    'CC-1014' as DEPT_ID,
    -- @todo - not always donations at the end of memo
    CONCAT(SUBSTRING_INDEX(%1, '_', 1) , ' | ', s.settlement_currency, ' | $dateDescription | ', COUNT(*), ' | ', ' Adjustment') as MEMO,
    IF(SUM(COALESCE(-settled_fee_amount, 0)) >= 0, SUM(COALESCE(-settled_fee_amount, 0)),0) as DEBIT,
    IF(SUM(COALESCE(-settled_fee_amount, 0)) >= 0, 0, SUM(COALESCE(settled_fee_amount, 0)))  as CREDIT,
    s.settlement_currency as CURRENCY,
    '' as GLENTRY_PROJECTID,
    0 as is_endowment,
    'Unrestricted' as GLDIMFUNDING
FROM civicrm_value_contribution_settlement s
  LEFT JOIN civicrm_contribution c ON c.id = s.entity_id
  LEFT JOIN civicrm_value_1_gift_data_7 gift ON c.id = gift.entity_id
WHERE (%1 = settlement_batch_reference)
  AND ( settled_fee_amount <> 0)
  AND trxn_id LIKE 'adyen adjustment%'
  AND is_template = 0
GROUP BY s.settlement_batch_reference
";
  }

  /**
   * Get the relevant clause to separate gateway level transactions into separate rows.
   *
   * Get the clause that finds the fees imposed that are not tied to transactions, rounding
   * or adjustments that are not tied to transactions.
   *
   * We exclude them from the main select & add them in by union to give the right memo details.
   * @return void
   */
  private function getGatewayLevelTransactionExcludeClause(): string {
    $gateway = $this->getAuditGateway();
    // COALESCE guards against NULL trxn_id, where "NULL NOT LIKE '...'" is
    // NULL, silently dropping the row from this clause and its inverse below.
    return "(COALESCE(trxn_id, '') NOT LIKE '{$gateway} transaction%'
      AND COALESCE(trxn_id, '') NOT LIKE '{$gateway} invoice%'
      AND COALESCE(trxn_id, '') NOT LIKE '{$gateway} rounding%'
      AND COALESCE(trxn_id, '') NOT LIKE '{$gateway} fee%'
      AND COALESCE(trxn_id, '') NOT LIKE '{$gateway} deposit%'
    )";
  }

  private function getGatewayLevelTransactionExcludeAdjustmentClause(): string {
    $gateway = $this->getAuditGateway();
    return "(
      COALESCE(trxn_id, '') NOT LIKE '{$gateway} adjustment%'
    )";
  }

  /**
   * @return array|string|string[]|null
   */
  private function getGatewayLevelTrxnIncludeClause(): string|array|null {
    $gatewayLevelTrxnIncludeClause = str_replace('NOT LIKE', 'LIKE', $this->getGatewayLevelTransactionExcludeClause());
    return str_replace(' AND ', ' OR ', $gatewayLevelTrxnIncludeClause);
  }

  /**
   * GLCode is currently calculated in sql but once Gift_Data.gl_code is populated we will use that instead.
   *
   * @return string
   */
  private function getGLCodeClause(): string {
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
  WHEN gift.channel = 'Interest'        THEN 45050   -- Interest (occurs with CCK)
  -- 3) Everything else -> Online Other Contributions
  -- remaining = 'Workplace Giving','Direct Solicitation','Planned Giving', 'Events','White Mail','Other Offline'
  ELSE '' -- default/fallback - this will cause the batch to report errors and not close.
END";
  }

  /**
   * Is the row coded to a major gifts account?
   *
   * Note getGLCodeClause()
   */
  private function getIsMajorGift(): string {
    return $this->getGLCodeClause() . ' IN (' . implode(', ', FinanceConstants::GL_MAJOR_GIFTS_ACCOUNTS) . ')';
  }

  /**
   * Is the row's revenue restricted funding?
   *
   * We calculate this off a combination of the gl_code and whether it is intended
   * for the endowment. (The gl_code is currently calculated in sql but we plan to move
   * that to a calculated field)
   */
  private function getIsRestrictedGift(): string {
    $endowmentFinancialType = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    return '(c.financial_type_id = ' . $endowmentFinancialType . ' OR ' . $this->getGLCodeClause() . ' = ' . FinanceConstants::GL_MAJOR_GIFTS_RESTRICTED . ')';
  }

  /**
   * @return string
   */
  private function getDeptIDClause(): string {
    // Endowment journals carry no cost centre. This is the SQL empty-string
    // literal, not a PHP empty string - the clause is interpolated as
    // "$deptIDClause as DEPT_ID", so dropping it would leave a bare alias.
    return $this->isEndowmentGateway() ? "''" : "-- cost centre: CC-1004 for major gifts, CC-1005 for online fundraising.
    IF(" . $this->getIsMajorGift() . ", 'CC-1004', 'CC-1005')";
  }

  /**
   * @return string
   */
  private function getRestrictionsClause(): string {
    return 'IF(' . $this->getIsRestrictedGift() . ", 'Temporarily Restricted', 'Unrestricted')";
  }

  /**
   * GL Code for a fee row: generally GL_FEE_DEFAULT except for cck
   * which has different overrides for foundation & endowment accounts.
   *
   * @return string
   */
  private function getFeeAccountCode(): string {
    return (string) (FinanceConstants::GATEWAY_ACCOUNTS_FEE_GL_CODES[$this->getGateway()] ?? FinanceConstants::GL_FEE_DEFAULT);
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getVendorCode(): string {
    $gateway = $this->getGateway();
    $accounts = $this->getGatewayAccounts();
    if (!isset($accounts[$gateway])) {
      throw new \CRM_Core_Exception('batch vendor ID missing for ' . $gateway);
    }
    $account = $accounts[$gateway];
    if ($this->isEndowmentGateway() && !empty($account['vendor_code_endowment'])) {
      return $account['vendor_code_endowment'];
    }
    // Vendor code is optional - e.g. wire & ach have no vendor in Intacct.
    return (string) ($account['vendor_code_foundation'] ?? '');
  }

  /**
   * Is this batch's gateway account an Endowment gateway account.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  private function isEndowmentGateway(): bool {
    $gateway = $this->getGateway();
    return !empty($this->getGatewayAccounts()[$gateway]['is_endowment']);
  }

  /**
   * Get the gateway name used as the trxn_id prefix by the audit process.
   *
   * This is usually the same as the batch's gateway (per getGateway()), but
   * some GatewayAccount records (e.g. stripemg) settle transactions that are
   * actually audited, and trxn_id-prefixed, under a different gateway's name
   * (stripe) - see GatewayAccount.gateway.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getAuditGateway(): string {
    $gateway = $this->getGateway();
    $accounts = $this->getGatewayAccounts();
    return $accounts[$gateway]['gateway'] ?? $gateway;
  }

  /**
   * @return string
   */
  private function getGateway(): string {
    $parts = explode('_', $this->batch['name']);
    $gateway = $parts[0];
    return $gateway;
  }

  /**
   * GatewayAccount records, indexed by name. Lazy-loaded & cached for the
   * duration of the run - see getGatewayAccounts().
   *
   * @var array|null
   */
  private ?array $gatewayAccounts = NULL;

  /**
   * Get the configured GatewayAccount records, indexed by name.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getGatewayAccounts(): array {
    if ($this->gatewayAccounts === NULL) {
      $this->gatewayAccounts = (array) GatewayAccount::get(FALSE)
        ->addSelect('name', 'gateway', 'is_endowment', 'vendor_code_foundation', 'vendor_code_endowment', 'balancing_account_foundation')
        ->execute()->indexBy('name');
    }
    return $this->gatewayAccounts;
  }

}

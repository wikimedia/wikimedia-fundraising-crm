<?php
/**
 * Since this update is so unwieldly it gets a file all to itself....
 */

/**
 * This function adds the missing records for refund transactions prior to 4.6.
 *
 * In the 4.3 transaction records were added for statuses Completed
 * Pending and Cancelled. The status Refunded was not a core status until 4.3
 * so transactions were NOT added in the upgrade to refunded status transactions.
 *
 * The original upgrade code is in the function
 * CRM_Upgrade_Incremental_php_FourThree::createFinancialRecords
 *
 * I tried re-editing it for use here but the sql was just too slow and we don't
 * want another long-outage. It's probably slower for this section than on the main
 * upgrade as the financial_trxn table has been populated in the meantime. The main
 * upgrade sql included adding and dropping columns on tables whereas here I
 * have used LAST_INSERT_ID() to get the inserted row ids.
 *
 * OTOH we only need to deal with ~16k records to php parsing is a reasonable option
 * (it might take a little while but it won't lock the tables so I don't think
 * the site would need to come down to run it).
 *
 * The records required are
 *  1) civicrm_financial_trxn - this is essentially a record of the payment transaction.
 *  2) civicrm_entity_financial_trxn this links the financial_trxn record to the contribution record.
 *  3) civicrm_financial_item - this table is used to record all payment data in easy to query way.
 *   http://wiki.civicrm.org/confluence/display/CRM/Financial+Items
 *   My understanding of the financial item table (which is rarely accessed) is that it does
 *   not contain any original information but holds information that would be otherwise hard
 *   to get in an sql statement in order to do aggregate reports around different financial
 *   accounts. Potentially WMF could use it to try to improve reporting about the money
 *   received from a particular payment processor but I have doubts we would ever go that way.
 *   I have doubts as to whether the financial_items are recorded 100% of the time when they should be.
 *
 *   In the case of the upgrade we need a row per line item in this table.
 *
 * Then IF there is a fee against the record we ALSO need
 *  1) a financial_trxn record for the fee_amount. The double-entry book-keeping fields
 *   (from_financial_account_id & to_financial_account_id) record this as a negative entry against the
 *   income account for the financial type and a positive entry against the expense account
 * 2) civicrm_entity_financial_trxn - to link the above financial_trxn record to the contribution.
 * 3) civicrm_financial_item - adds a row to show the fee recorded against the financial_item expense.
 *
 */
function _wmf_civicrm_update_7070_fill_refund_transaction_data() {
  civicrm_initialize();
  $paymentInstrumentMapping = _wmf_civicrm_update_7070_helper_get_payment_instrument_mapping();
  $financialAccountId = _wmf_civicrm_update_7070_helper_get_default_financial_account_id();
  $paidStatus = _wmf_civicrm_update_7070_helper_get_paid_status();
  $refundStatus = _wmf_civicrm_update_7070_helper_get_refund_status();
  $completedStatus = _wmf_civicrm_update_7070_helper_get_completed_status();
  $domainContact_id = _wmf_civicrm_update_7070_helper_get_domain_contact();
  $incomeAccountMapping = _wmf_civicrm_update_7070_helper_get_account_mappings('Income');
  $expenseAccountMapping = _wmf_civicrm_update_7070_helper_get_account_mappings('Expense');

  // 16381 rows in set (17.41 sec)
  $result = CRM_Core_DAO::executeQuery(
    "SELECT cont.id as contribution_id,
      cont.payment_instrument_id,
      cont.currency,
      cont.total_amount,
      cont.net_amount,
      cont.fee_amount,
      cont.trxn_id,
      cont.check_number,
      cont.receive_date,
      cont.contact_id,
      cont.financial_type_id
    FROM civicrm_contribution cont
    LEFT JOIN civicrm_entity_financial_trxn efa
      ON  efa.entity_id = cont.id AND efa.entity_table = 'civicrm_contribution'
    WHERE    cont.contribution_status_id = $refundStatus
      AND efa.id IS NULL
    "
  );

  // Warning: continuing to read beyond this line may cause permanent brain injury.
  // Standard faustian conditions apply.

  while ($result->fetch()) {
    // We will fully handle & commit each row & if we need to restart we can.
    // For each contribution we need a financial_txn record, an entity_financial_transaction
    // record linking it to the contribution and a financial_item record linking it to the line_item
    // (this latter is probably unused for most CiviCRM installs including wmf)
    $transaction = new CRM_Core_Transaction();

    try {
      $trxn_date = CRM_Utils_Date::isoToMysql($result->receive_date);
      if (!$result->fee_amount) {
        $result->fee_amount = 0;
      }
      if (!$result->net_amount) {
        $result->net_amount = $result->total_amount - $result->fee_amount;
      }

      $sql = "
        INSERT INTO civicrm_financial_trxn (
          payment_instrument_id,
          currency,
          total_amount,
          net_amount,
          fee_amount,
          trxn_id,
          status_id,
          check_number,
          to_financial_account_id,
          from_financial_account_id,
          trxn_date
        )
       SELECT
         {$result->payment_instrument_id},
         '{$result->currency}',
         {$result->total_amount},
         {$result->net_amount},
         {$result->fee_amount},
         '{$result->trxn_id}',
         $refundStatus,
         '{$result->check_number}',
         " . (empty($result->payment_instrument_id) ? $financialAccountId : $paymentInstrumentMapping[$result->payment_instrument_id]) . " as to_financial_account_id,
         NULL,
         '{$trxn_date}'
        ";
      CRM_Core_DAO::executeQuery($sql);
      $financialTransactionID = CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()");

      $sql = "
        INSERT INTO civicrm_entity_financial_trxn (
          entity_table,
          entity_id,
          financial_trxn_id,
          amount
        )
        SELECT
          'civicrm_contribution',
          {$result->contribution_id},
          $financialTransactionID ,
          {$result->total_amount}
      ";
      CRM_Core_DAO::executeQuery($sql);

      $sql = "
        INSERT INTO civicrm_financial_item (
          transaction_date,
          contact_id,
          amount,
          currency,
          entity_table,
          entity_id,
          description,
          status_id,
          financial_account_id
        )

        SELECT
          '{$trxn_date}',
          {$result->contact_id},
          {$result->total_amount},
          '{$result->currency}',
          'civicrm_line_item',
          li.id as line_item_id,
          li.label as line_item_label,
          {$paidStatus} as status_id,"
        // The line item actually has the same financial type as the contribution.
        // ie. 0 rows from SELECT c.id FROM civicrm_contribution c LEFT JOIN civicrm_line_item li ON li.contribution_id = c.id WHERE li.financial_type_id <> c.financial_type_id LIMIT 5;
        . $incomeAccountMapping[$result->financial_type_id] . "
         FROM  civicrm_line_item li
         INNER JOIN civicrm_contribution con
           ON li.contribution_id = con.id
          WHERE con.id = {$result->contribution_id}";

      CRM_Core_DAO::executeQuery($sql);


      if (!empty($result->fee_amount)) {
        $sql = "
          INSERT INTO civicrm_financial_trxn (
            payment_instrument_id,
            currency,
            total_amount,
            net_amount,
            fee_amount,
            trxn_id,
            status_id,
            check_number,
            to_financial_account_id,
            from_financial_account_id,
            trxn_date
          )

          SELECT
            {$result->payment_instrument_id},
            '{$result->currency}',
            {$result->fee_amount},
            NULL,
            NULL,
            '{$result->trxn_id}',
            $completedStatus,
            '{$result->check_number}',
            {$expenseAccountMapping[$result->financial_type_id]},
            "
          // Compared to where this calculation is being done above the difference is that the money is being transferred from the
          // internal account for the payment instrument to an expense account.
          // This is an 'under-the-hood' double-entry bookkeeping entry which has limited visibility
          // with only very specific accounting related functions accessing it.
          . (empty($result->payment_instrument_id) ? $financialAccountId : $paymentInstrumentMapping[$result->payment_instrument_id])
          . " as from_financial_account_id,
            '{$trxn_date}'
        ";
        CRM_Core_DAO::executeQuery($sql);
        $financialFeeTransactionID = CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()");
        $sql = "
          INSERT INTO civicrm_entity_financial_trxn (
            entity_table,
            entity_id,
            financial_trxn_id,
            amount
          )
          SELECT
            'civicrm_contribution',
            {$result->contribution_id},
            {$financialFeeTransactionID},
            {$result->fee_amount}
        ";
        CRM_Core_DAO::executeQuery($sql);
        //add fee related entries to financial item table

        $sql = "
          INSERT INTO civicrm_financial_item (
            transaction_date,
            contact_id,
            amount,
            currency,
            entity_table,
            entity_id,
            description,
            status_id,
            financial_account_id
          )
          SELECT
            '{$trxn_date}',
            {$domainContact_id} as contact_id,
            {$result->fee_amount},
            '{$result->currency}',
            'civicrm_financial_trxn',
            {$financialFeeTransactionID},
            'Fee',
            {$paidStatus} as status_id,
            {$expenseAccountMapping[$result->financial_type_id]} as financial_account_id
          ";
        CRM_Core_DAO::executeQuery($sql);
        $financialFeeItemID = CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()");

        //add entries to entity_financial_trxn table
        $sql = "
          INSERT INTO civicrm_entity_financial_trxn (
            entity_table,
            entity_id,
            financial_trxn_id,
            amount
          )
          SELECT
            'civicrm_financial_item' as entity_table,
            {$financialFeeItemID} as entity_id,
            {$financialFeeTransactionID},
            {$result->fee_amount}";
        CRM_Core_DAO::executeQuery($sql);

      }

      $transaction->commit();
    }
    catch (Exception $ex) {
      $transaction->rollback()->commit();
      throw $ex;
    }
  }
}
/**
 * Get the mappings for financial type to financial account for income.
 *
 * @param string $type
 *   Income or Expense
 *
 * @return array
 */
function _wmf_civicrm_update_7070_helper_get_account_mappings($type) {
  $incomeAccountMappings = array();
  $accountRelationships = CRM_Core_PseudoConstant::get('CRM_Financial_DAO_EntityFinancialAccount', 'account_relationship', CRM_Core_DAO::$_nullArray, 'validate');
  $result = CRM_Core_DAO::executeQuery(
    " SELECT entity_id, financial_account_id FROM civicrm_entity_financial_account efa
    WHERE  entity_table = 'civicrm_financial_type'
    AND efa.account_relationship = " . array_search("$type Account is", $accountRelationships)
  );

  while ($result->fetch()) {
    $incomeAccountMappings[$result->entity_id] = $result->financial_account_id;
  }
print_r($incomeAccountMappings);
  return $incomeAccountMappings;
}

/**
 * Get the id of the domain contact record.
 *
 * (we are supposed to look up the record for each account but since they all refer to
 * the domain contact we are shortcutting that.
 *
 * @return int
 */
function _wmf_civicrm_update_7070_helper_get_domain_contact() {
  return CRM_Core_DAO::singleValueQuery("SELECT contact_id FROM civicrm_domain");
}

/**
 * Get the status ID associated with 'Refunded' contributions that were not upgraded.
 *
 * Getting a list of statuses & grabbing the first
 * refund will retrieve the one with lowest id - which will be the wmf-added one
 * not the upgrade script added one.
 *
 * @return int
 */
function _wmf_civicrm_update_7070_helper_get_refund_status() {
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  return array_search('Refunded', $contributionStatus);
}

/**
 * Get the status ID associated with 'Refunded' contributions that were not upgraded.
 *
 * Getting a list of statuses & grabbing the first
 * refund will retrieve the one with lowest id - which will be the wmf-added one
 * not the upgrade script added one.
 *
 * @return int
 */
function _wmf_civicrm_update_7070_helper_get_completed_status() {
  $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
  return array_search('Completed', $contributionStatus);
}

/**
 * Get the financial account to use when payment instrument is not recorded.
 *
 * @return int
 */
function _wmf_civicrm_update_7070_helper_get_default_financial_account_id() {
  $accountType = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL,
    " AND v.name = 'Asset' "));
  $query = "
    SELECT id
    FROM   civicrm_financial_account
    WHERE  is_default = 1
    AND    financial_account_type_id = {$accountType}
  ";
  return CRM_Core_DAO::singleValueQuery($query);
}

/**
 * Get a mapping of payment instruments to financial account.
 *
 * The payment instrument is used to look up a record from the financial account table or
 * a default is used where there is no payment instrument (60 rows in this category).
 *
 * @return array
 */
function _wmf_civicrm_update_7070_helper_get_payment_instrument_mapping() {

  $paymentInstrumentMapping = array();
  $paymentInstrumentResult = CRM_Core_DAO::executeQuery(
    "SELECT  cov.value as instrument_id,   ceft.financial_account_id
     FROM       civicrm_entity_financial_account ceft
     INNER JOIN civicrm_option_value cov ON cov.id = ceft.entity_id AND ceft.entity_table = 'civicrm_option_value'
     INNER JOIN civicrm_option_group cog ON cog.id = cov.option_group_id
     WHERE      cog.name = 'payment_instrument'"
  );
  while ($paymentInstrumentResult->fetch()) {
    $paymentInstrumentMapping[$paymentInstrumentResult->instrument_id] = $paymentInstrumentResult->financial_account_id;
  }
  return $paymentInstrumentMapping;
}

/**
 * Helper function for install script.
 *
 * Get the status for 'Paid' for the financial_item table.
 *
 * @return int
 */
function _wmf_civicrm_update_7070_helper_get_paid_status() {
  $financialItemStatus = CRM_Core_PseudoConstant::get('CRM_Financial_DAO_FinancialItem', 'status_id', CRM_Core_DAO::$_nullArray, 'validate');
  return array_search('Paid', $financialItemStatus);
}

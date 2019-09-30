<?php

use wmf_eoy_receipt\EoySummary;

require_once __DIR__ . '/../../EoySummary.php';

/**
 * Tests for EOY Summary
 *
 * @group EOYSummary
 */
class EoySummaryTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $jobIds = [];

  public function tearDown() {
    if ($this->jobIds) {
      $idList = implode(',', $this->jobIds);
      db_query("DELETE from wmf_eoy_receipt_donor WHERE job_id in ($idList)")
        ->execute();
      db_query("DELETE from wmf_eoy_receipt_job WHERE job_id in ($idList)")
        ->execute();
    }
    parent::tearDown();
  }

  public function testCalculate() {
    $contactOnetime = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Onetime',
      'last_name' => 'walrus',
      'contact_type' => 'Individual',
      'email' => 'onetime@walrus.org',
    ]);
    $contactRecur = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Recurring',
      'last_name' => 'Rabbit',
      'contact_type' => 'Individual',
      'email' => 'recurring@rabbit.org',
      'preferred_language' => 'pt_BR',
    ]);
    $this->ids['Contact'][$contactOnetime['id']] = $contactOnetime['id'];
    $this->ids['Contact'][$contactRecur['id']] = $contactRecur['id'];
    $processor = $this->callAPISuccessGetSingle('PaymentProcessor', [
      'name' => 'ingenico',
      'is_test' => 1,
    ]);
    $recurring = $this->callAPISuccess('ContributionRecur', 'create', [
      'contact_id' => $contactRecur['id'],
      'amount' => 200,
      'currency' => 'PLN',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'trxn_id' => mt_rand(),
      'payment_processor_id' => $processor['id'],
    ]);
    $this->ids['ContributionRecur'][$recurring['id']] = $recurring['id'];
    $financialTypeCash = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash'
    );
    $financialTypeEndowment = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift'
    );
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );
    $refundedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Refunded'
    );
    $originalCurrencyField = wmf_civicrm_get_custom_field_name('original_currency');
    $originalAmountField = wmf_civicrm_get_custom_field_name('original_amount');
    $contribCashTooEarly = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2017-12-31',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '10',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '100',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCash = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01', // FIXME: edge case found?
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashRecurring = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-08-08',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashOnetimer = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01',
      'contact_id' => $contactOnetime['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashUSD = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-02-02',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'USD',
      $originalAmountField => '20',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashRefunded = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-03-03',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '21',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '210',
      'contribution_status_id' => $refundedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribEndowment = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-04-04',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '400',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeEndowment,
    ]);
    foreach ([
               $contribCashTooEarly,
               $contribCash,
               $contribCashRecurring,
               $contribCashOnetimer,
               $contribEndowment,
               $contribCashRefunded,
               $contribCashUSD,
             ] as $contrib) {
      $this->ids['Contribution'][$contrib['id']] = $contrib['id'];
    }
    $summaryObject = new EoySummary(['year' => 2018]);
    $jobId = $summaryObject->calculate_year_totals();
    $this->jobIds[] = $jobId;
    $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
    status = 'queued'
    AND job_id = $jobId
    AND email = :email
EOS;
    $result = db_query($sql, [':email' => 'onetime@walrus.org'])->fetchAssoc();
    $this->assertEmpty($result);
    $result = db_query($sql, [':email' => 'recurring@rabbit.org'])->fetchAssoc();
    $rollup = explode(',', $result['contributions_rollup']);
    sort($rollup);
    unset($result['contributions_rollup']);
    $this->assertEquals(['name' => 'Recurring',
      'job_id' => $jobId,
      'email' => 'recurring@rabbit.org',
      'preferred_language' => 'pt_BR',
      'status' => 'queued',
    ], $result);
    $this->assertEquals(['2018-02-02 20.00 USD','2018-08-08 30.00 PLN'], $rollup);
  }

  /**
   * Test that we include contributions from two contact records with the same
   * email when one of them has a recurring contribution.
   */
  public function testCalculateDedupe() {
    $olderContact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Cassius',
      'last_name' => 'Clay',
      'contact_type' => 'Individual',
      'email' => 'goat@wbaboxing.com',
      'preferred_language' => 'en_US',
    ]);
    $newerContact = $this->callAPISuccess('Contact', 'create', [
      'first_name' => 'Muhammad',
      'last_name' => 'Ali',
      'contact_type' => 'Individual',
      'email' => 'goat@wbaboxing.com',
      'preferred_language' => 'ar_EG',
    ]);
    $this->ids['Contact'][$olderContact['id']] = $olderContact['id'];
    $this->ids['Contact'][$newerContact['id']] = $newerContact['id'];
    $processor = $this->callAPISuccessGetSingle('PaymentProcessor', [
      'name' => 'ingenico',
      'is_test' => 1,
    ]);
    $recurring = $this->callAPISuccess('ContributionRecur', 'create', [
      'contact_id' => $olderContact['id'],
      'amount' => 200,
      'currency' => 'PLN',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'trxn_id' => mt_rand(),
      'payment_processor_id' => $processor['id'],
    ]);
    $this->ids['ContributionRecur'][$recurring['id']] = $recurring['id'];
    $financialTypeCash = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash'
    );
    $completedStatusId = CRM_Contribute_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );
    $originalCurrencyField = wmf_civicrm_get_custom_field_name('original_currency');
    $originalAmountField = wmf_civicrm_get_custom_field_name('original_amount');
    $contribCashOlder = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-02-02',
      'contact_id' => $olderContact['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '400',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashRecurringOlder = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-03-03',
      'contact_id' => $olderContact['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $contribCashNewer = $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-04-04',
      'contact_id' => $newerContact['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    foreach ([
               $contribCashOlder,
               $contribCashNewer,
               $contribCashRecurringOlder
           ] as $contrib) {
      $this->ids['Contribution'][$contrib['id']] = $contrib['id'];
    }
    $summaryObject = new EoySummary(['year' => 2018]);
    $jobId = $summaryObject->calculate_year_totals();
    $this->jobIds[] = $jobId;
    $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
  status = 'queued'
  AND job_id = $jobId
  AND email = :email
EOS;
    $result = db_query($sql, [':email' => 'goat@wbaboxing.com'])->fetchAssoc();
    $rollup = explode(',', $result['contributions_rollup']);
    sort($rollup);
    unset($result['contributions_rollup']);
    $this->assertEquals(['name' => 'Muhammad',
      'job_id' => $jobId,
      'email' => 'goat@wbaboxing.com',
      'preferred_language' => 'ar_EG',
      'status' => 'queued',
    ], $result);
    $this->assertEquals([
      '2018-02-02 400.00 PLN',
      '2018-03-03 30.00 PLN',
      '2018-04-04 200.00 PLN'], $rollup);
  }

  /**
   * Test the render function.
   *
   * @throws \Exception
   */
  public function testRender() {
    variable_set('thank_you_from_address', 'bobita@example.org');
    variable_set('thank_you_from_name', 'Bobita');
    $eoyClass = new EoySummary(['year' => 2018]);
    $email = $eoyClass->render_letter((object) [
      'job_id' => '132',
      'email' => 'bob@example.com',
      'preferred_language' => NULL,
      'name' => 'Bob',
      'status' => 'queued',
      'contributions_rollup' => '2019-02-01 50.00 USD,2019-03-02 800.00 USD,2019-05-03 50.00 USD,2019-10-20 50.00 USD,2019-07-12 50.00 USD,2019-01-26 50.00 USD,2019-10-11 100.00 USD,2019-05-11 800.00 USD,2019-10-12 800.00 USD,2019-10-14 50.00 USD,2019-09-02 800.00 USD,2019-12-09 1200.00 USD,2019-12-22 800.00 USD,2019-11-22 800.00 USD,2019-05-05 800.00 USD,2019-06-06 50.00 USD,2019-07-07 50.00 USD,2019-08-08 50.00 USD,2019-06-08 50.00 USD,2019-08-08 50.00 USD,2019-03-03 800.00 USD,2019-06-04 50.00 USD,2019-10-22 50.00 USD,2019-10-03 100.00 USD,2019-10-09 1200.00 USD,2019-10-12 100.00 USD,2019-10-15 50.00 USD',
    ]);
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'Your 2018 contributions to Wikipedia
',
      'plaintext' => 'Dear Bob,
Thank you for your donations during 2018.

For your records, your contributions were as follows:

Date        Amount
2019-01-26  50 USD
2019-02-01  50 USD
2019-03-02  800 USD
2019-03-03  800 USD
2019-05-03  50 USD
2019-05-05  800 USD
2019-05-11  800 USD
2019-06-04  50 USD
2019-06-06  50 USD
2019-06-08  50 USD
2019-07-07  50 USD
2019-07-12  50 USD
2019-08-08  50 USD
2019-08-08  50 USD
2019-09-02  800 USD
2019-10-03  100 USD
2019-10-09  1200 USD
2019-10-11  100 USD
2019-10-12  100 USD
2019-10-12  800 USD
2019-10-14  50 USD
2019-10-15  50 USD
2019-10-20  50 USD
2019-10-22  50 USD
2019-11-22  800 USD
2019-12-09  1200 USD
2019-12-22  800 USD

Total USD:  9800
',
      'html' => '<p>
Dear Bob,
</p>

<p>
Thank you for your donations during 2018.
</p>

<p>
For your records, your contributions were as follows:
</p>

<table>
<thead>
<tr>
<th>Date</th><th>Amount</th>
</tr>
</thead>
<tbody>
<tr>
<td>2019-01-26</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-02-01</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-03-02</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-03-03</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-05-03</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-05-05</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-05-11</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-06-04</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-06-06</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-06-08</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-07-07</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-07-12</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-08-08</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-08-08</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-09-02</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-10-03</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>2019-10-09</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>2019-10-11</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>2019-10-12</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>2019-10-12</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-10-14</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-10-15</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-10-20</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-10-22</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>2019-11-22</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>2019-12-09</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>2019-12-22</td><td width="100%">800 USD</td>
</tr>
<tr>
  <td>Total USD</td><td width="100%">9800</td>
</tr>
<tbody>
</table>
',
    ], $email);
  }

}

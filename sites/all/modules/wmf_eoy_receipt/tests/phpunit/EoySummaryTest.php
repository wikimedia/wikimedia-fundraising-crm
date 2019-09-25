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
      'contributions_rollup' => explode(',', '%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 1200.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 1200.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 50.00 USD'),
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
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  100 USD
%Y-%m-%d  1200 USD
%Y-%m-%d  100 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  100 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  1200 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  100 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD

Total USD:  12400
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
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
  <td>Total USD</td><td width="100%">12400</td>
</tr>
<tbody>
</table>
',
    ], $email);
  }

}

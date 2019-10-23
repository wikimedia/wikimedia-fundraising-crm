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
      'preferred_language' => 'en',
      'name' => 'Bob',
      'status' => 'queued',
      'contributions_rollup' => '2018-02-01 50.00 USD,2018-03-02 800.00 USD,2018-05-03 50.00 USD,2018-10-20 50.00 USD,2018-07-12 50.00 USD,2018-01-26 50.00 USD,2018-10-11 100.00 USD,2018-05-11 800.00 USD,2018-10-12 800.00 USD,2018-10-14 50.00 USD,2018-09-02 800.00 USD,2018-12-09 1200.00 USD,2018-12-22 800.00 USD,2018-11-22 800.00 USD,2018-05-05 800.00 USD,2018-06-06 50.00 USD,2018-07-07 50.00 USD,2018-08-08 50.00 USD,2018-06-08 50.00 USD,2018-08-08 50.00 USD,2018-03-03 800.00 USD,2018-06-04 50.00 USD,2018-10-22 50.00 USD,2018-10-03 100.00 USD,2018-10-09 1200.00 USD,2018-10-12 100.00 USD,2018-10-15 50.00 USD',
    ]);
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'Your 2018 contributions to Wikipedia
',
      'plaintext' => 'Dear Bob,

I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.

Your hard earned money pays our bills, runs our servers, and helps us recruit the world\'s smartest people to ensure that Wikipedia will always be a resilient, neutral source for learning.

Outside of those practical details, your donations are deeply meaningful to we who work to serve you. Your donations show us that our work matters, and is worth supporting. Thank you.

Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018.

Your 2018 total was USD 9800.

Donation 1: 50 USD 2018-01-26
Donation 2: 50 USD 2018-02-01
Donation 3: 800 USD 2018-03-02
Donation 4: 800 USD 2018-03-03
Donation 5: 50 USD 2018-05-03
Donation 6: 800 USD 2018-05-05
Donation 7: 800 USD 2018-05-11
Donation 8: 50 USD 2018-06-04
Donation 9: 50 USD 2018-06-06
Donation 10: 50 USD 2018-06-08
Donation 11: 50 USD 2018-07-07
Donation 12: 50 USD 2018-07-12
Donation 13: 50 USD 2018-08-08
Donation 14: 50 USD 2018-08-08
Donation 15: 800 USD 2018-09-02
Donation 16: 100 USD 2018-10-03
Donation 17: 1200 USD 2018-10-09
Donation 18: 100 USD 2018-10-11
Donation 19: 100 USD 2018-10-12
Donation 20: 800 USD 2018-10-12
Donation 21: 50 USD 2018-10-14
Donation 22: 50 USD 2018-10-15
Donation 23: 50 USD 2018-10-20
Donation 24: 50 USD 2018-10-22
Donation 25: 800 USD 2018-11-22
Donation 26: 1200 USD 2018-12-09
Donation 27: 800 USD 2018-12-22

If for whatever reason you wish to cancel your donation, follow these easy cancellation instructions:
https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en
',
      'html' => '<p>
Dear Bob,
</p>

<p>
I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.
</p>

<p>
Your hard earned money pays our bills, runs our servers, and helps us recruit the world\'s smartest people to ensure that Wikipedia will always be a resilient, neutral source for learning.
</p>

<p>
Outside of those practical details, your donations are deeply meaningful to we who work to serve you. Your donations show us that our work matters, and is worth supporting. Thank you.
</p>

<p>
Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018.
</p>

<p>
Your 2018 total was USD 9800.
</p>

<p>
Donation 1: 50 USD 2018-01-26
</p>
<p>
Donation 2: 50 USD 2018-02-01
</p>
<p>
Donation 3: 800 USD 2018-03-02
</p>
<p>
Donation 4: 800 USD 2018-03-03
</p>
<p>
Donation 5: 50 USD 2018-05-03
</p>
<p>
Donation 6: 800 USD 2018-05-05
</p>
<p>
Donation 7: 800 USD 2018-05-11
</p>
<p>
Donation 8: 50 USD 2018-06-04
</p>
<p>
Donation 9: 50 USD 2018-06-06
</p>
<p>
Donation 10: 50 USD 2018-06-08
</p>
<p>
Donation 11: 50 USD 2018-07-07
</p>
<p>
Donation 12: 50 USD 2018-07-12
</p>
<p>
Donation 13: 50 USD 2018-08-08
</p>
<p>
Donation 14: 50 USD 2018-08-08
</p>
<p>
Donation 15: 800 USD 2018-09-02
</p>
<p>
Donation 16: 100 USD 2018-10-03
</p>
<p>
Donation 17: 1200 USD 2018-10-09
</p>
<p>
Donation 18: 100 USD 2018-10-11
</p>
<p>
Donation 19: 100 USD 2018-10-12
</p>
<p>
Donation 20: 800 USD 2018-10-12
</p>
<p>
Donation 21: 50 USD 2018-10-14
</p>
<p>
Donation 22: 50 USD 2018-10-15
</p>
<p>
Donation 23: 50 USD 2018-10-20
</p>
<p>
Donation 24: 50 USD 2018-10-22
</p>
<p>
Donation 25: 800 USD 2018-11-22
</p>
<p>
Donation 26: 1200 USD 2018-12-09
</p>
<p>
Donation 27: 800 USD 2018-12-22
</p>

<p>
If for whatever reason you wish to cancel your donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en">easy cancellation instructions</a>.
</p>
',
    ], $email);
  }

  /**
   * Test the render function with donations in multiple currencies.
   *
   * @throws \Exception
   */
  public function testRenderMultiCurrency() {
    variable_set('thank_you_from_address', 'bobita@example.org');
    variable_set('thank_you_from_name', 'Bobita');
    $eoyClass = new EoySummary(['year' => 2018]);
    $email = $eoyClass->render_letter((object) [
      'job_id' => '132',
      'email' => 'bob@example.com',
      'preferred_language' => 'en',
      'name' => 'Bob',
      'status' => 'queued',
      'contributions_rollup' => '2018-02-01 50.00 USD,2018-03-02 800.00 CAD,2018-05-03 20.00 USD,2018-10-20 50.00 CAD',
    ]);
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'Your 2018 contributions to Wikipedia
',
      'plaintext' => 'Dear Bob,

I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.

Your hard earned money pays our bills, runs our servers, and helps us recruit the world\'s smartest people to ensure that Wikipedia will always be a resilient, neutral source for learning.

Outside of those practical details, your donations are deeply meaningful to we who work to serve you. Your donations show us that our work matters, and is worth supporting. Thank you.

Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018.

Your 2018 total was USD 70.
Your 2018 total was CAD 850.

Donation 1: 50 USD 2018-02-01
Donation 2: 800 CAD 2018-03-02
Donation 3: 20 USD 2018-05-03
Donation 4: 50 CAD 2018-10-20

If for whatever reason you wish to cancel your donation, follow these easy cancellation instructions:
https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en
',
      'html' => '<p>
Dear Bob,
</p>

<p>
I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.
</p>

<p>
Your hard earned money pays our bills, runs our servers, and helps us recruit the world\'s smartest people to ensure that Wikipedia will always be a resilient, neutral source for learning.
</p>

<p>
Outside of those practical details, your donations are deeply meaningful to we who work to serve you. Your donations show us that our work matters, and is worth supporting. Thank you.
</p>

<p>
Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018.
</p>

<p>
Your 2018 total was USD 70.
</p>
<p>
Your 2018 total was CAD 850.
</p>

<p>
Donation 1: 50 USD 2018-02-01
</p>
<p>
Donation 2: 800 CAD 2018-03-02
</p>
<p>
Donation 3: 20 USD 2018-05-03
</p>
<p>
Donation 4: 50 CAD 2018-10-20
</p>

<p>
If for whatever reason you wish to cancel your donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en">easy cancellation instructions</a>.
</p>
',
    ], $email);
  }
}

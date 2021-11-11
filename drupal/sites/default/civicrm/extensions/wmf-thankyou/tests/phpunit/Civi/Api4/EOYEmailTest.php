<?php
namespace Civi\Api4;
require_once __DIR__ . '/../../../../../../../../all/modules/wmf_eoy_receipt/EoySummary.php';

use Civi\Omnimail\MailFactory;
use Civi\Test\Api3TestTrait;
use CRM_Core_DAO;
use CRM_Core_PseudoConstant;
use PHPUnit\Framework\TestCase;
use wmf_eoy_receipt\EoySummary;
use Civi\Test\Mailer;

class EOYEmailTest extends TestCase {

  use Api3TestTrait;
  protected $jobIds = [];

  /**
   * Created IDs.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    parent::setUp();
    variable_set('thank_you_from_address', 'bobita@example.org');
    variable_set('thank_you_from_name', 'Bobita');
    $mailfactory = MailFactory::singleton();
    $mailfactory->setActiveMailer(NULL, new Mailer());
  }

  public function tearDown(): void {
    if ($this->jobIds) {
      $idList = implode(',', $this->jobIds);
      CRM_Core_DAO::executeQuery("DELETE from wmf_eoy_receipt_donor WHERE job_id in ($idList)");
      CRM_Core_DAO::executeQuery("DELETE from wmf_eoy_receipt_job WHERE job_id in ($idList)");
    }
    foreach ($this->ids as $entity => $entityIDs) {
      foreach ($entityIDs as $entityID) {
        if ($entity === 'Contact') {
          $this->cleanUpContact($entityID);
        }
      }
    }
    parent::tearDown();
  }

  /**
   * Test that Japanese characters in a name are rendered correctly.
   *
   * We no longer use the Japanese template as the name is not
   * in it due to https://phabricator.wikimedia.org/T271189
   *
   * @throws \API_Exception
   */
  public function testRenderEmailInJapanese(): void {
    $contactID = Contact::create()
      ->setCheckPermissions(FALSE)
      // Suzuki is a common Japanese name - the last name here is Suzuki in kanji.
      ->setValues(['last_name' => 'Suzuki', 'first_name' => '鈴木', 'preferred_language' => 'ca_ES', 'contact_type' => 'Individual'])
      ->addChain('add_email',
        Email::create()
          ->setValues([
            'email' => 'suzuki@example.com',
          ])
          ->addValue('contact_id', '$id'))
      ->addChain('add_a_donation',
        Contribution::create()
          ->setValues([
            'total_amount' => 5,
            'currency' => 'JPY',
            'receive_date' => '2020-08-06',
            'financial_type_id:name' => 'Donation',
          ])
          ->addValue('contact_id', '$id')
      )->execute()->first()['id'];

    $message = EOYEmail::render()->setCheckPermissions(FALSE)
      ->setYear(2020)->setContactID($contactID)->execute()->first();
    $this->assertContains('Hola 鈴木', $message['html']);
  }

  /**
   * @throws \API_Exception
   */
  public function testCalculate(): void {
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
    $completedStatusId = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );
    $refundedStatusId = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Refunded'
    );
    $originalCurrencyField = wmf_civicrm_get_custom_field_name('original_currency');
    $originalAmountField = wmf_civicrm_get_custom_field_name('original_amount');
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2017-12-31 22:59:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '10',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '100',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01', // FIXME: edge case found?
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-08-08 22:00:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01',
      'contact_id' => $contactOnetime['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-02-02 08:10:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'USD',
      $originalAmountField => '20',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-03-03',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '21',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '210',
      'contribution_status_id' => $refundedStatusId,
      'financial_type_id' => $financialTypeCash,
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-04-04',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '400',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeEndowment,
    ]);
    $summaryObject = new EoySummary(['year' => 2018]);
    $jobId = $summaryObject->calculate_year_totals();
    $this->jobIds[] = $jobId;

    $result = $this->getWMFReceiptDonorRows($jobId, 'onetime@walrus.org');
    $this->assertEmpty($result);
    $result = $this->getWMFReceiptDonorRows($jobId, 'recurring@rabbit.org');
    $rollup = explode(',', $result['contributions_rollup']);
    sort($rollup);
    unset($result['contributions_rollup']);
    $this->assertEquals([
      'name' => 'Recurring',
      'job_id' => $jobId,
      'email' => 'recurring@rabbit.org',
      'preferred_language' => 'pt_BR',
      'status' => 'queued',
    ], $result);
    $this->assertEquals([
      '2018-02-01 20.00 USD',
      '2018-08-08 30.00 PLN',
    ], $rollup);
  }

  /**
   * Test that we include contributions from two contact records with the same
   * email when one of them has a recurring contribution.
   *
   * @throws \API_Exception
   */
  public function testCalculateDedupe(): void {
    $this->setUpContactsSharingEmail();
    $summaryObject = new EoySummary(['year' => 2018]);
    $jobId = $summaryObject->calculate_year_totals();
    $this->jobIds[] = $jobId;
    $result = $this->getWMFReceiptDonorRows($jobId, 'goat@wbaboxing.com');
    $rollup = explode(',', $result['contributions_rollup']);
    sort($rollup);
    unset($result['contributions_rollup']);
    $this->assertEquals([
      'name' => 'Muhammad',
      'job_id' => $jobId,
      'email' => 'goat@wbaboxing.com',
      'preferred_language' => 'ar_EG',
      'status' => 'queued',
    ], $result);
    $this->assertEquals([
      '2018-02-01 400.00 PLN',
      '2018-03-02 30.00 PLN',
      '2018-04-03 200.00 PLN',
    ], $rollup);
  }

  /**
   * @throws \API_Exception
   */
  public function testCalculateSingleContactId(): void {
    $contact = $this->addTestContact(['email' => 'jimmysingle@example.com',]);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-27 22:59:00']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-28 22:59:00']);

    $summaryObject = new EoySummary([
      'year' => 2019,
      'contact_id' => $contact['id'],
    ]);
    $jobId = $summaryObject->calculate_year_totals();
    $this->jobIds[] = $jobId;
    $result = $this->getWMFReceiptDonorRows($jobId, 'jimmysingle@example.com');
    $this->assertEquals([
      'name' => 'Jimmy',
      'job_id' => $jobId,
      'email' => 'jimmysingle@example.com',
      'preferred_language' => 'en_US',
      'status' => 'queued',
      'contributions_rollup' => '2019-11-27 10.00 USD,2019-11-28 10.00 USD',
    ], $result);
  }

  /**
   * Test that we create activity records for each contact with a
   * shared email.
   */
  public function testCreateActivityRecords(): void {
    $contactIds = $this->setUpContactsSharingEmail();
    $summaryObject = new EoySummary(['year' => 2018]);
    $this->jobIds[] = $summaryObject->calculate_year_totals();
    $summaryObject->send_letters();
    $this->assertEquals(1, MailFactory::singleton()->getMailer()->count());
    $mailing = MailFactory::singleton()->getMailer()->getMailings()[0];
    $this->assertRegExp('/Cancel_or_change_recurring_giving/', $mailing['html']);
    foreach ($contactIds as $contactId) {
      $activity = $this->callAPISuccessGetSingle('Activity', [
        'activity_type_id' => 'wmf_eoy_receipt_sent',
        'target_contact_id' => $contactId,
      ]);
      $this->assertEquals(
        'Sent contribution summary receipt for year 2018 to goat@wbaboxing.com',
        $activity['subject']
      );
      $this->assertEquals(
        $mailing['html'],
        $activity['details']
      );
    }
  }

  /**
   * Test that we don't include cancellation instructions for
   * donors whose donation is already cancelled.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \API_Exception
   */
  public function testSendWithRecurringDonationsCancelled(): void {
    $this->setUpContactsSharingEmail();
    // the above call sets some recurring contribution IDs
    foreach ($this->ids['ContributionRecur'] as $recurId) {
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurId,
        'contribution_status_id' => 'Cancelled',
      ]);
    }
    $summaryObject = new EoySummary(['year' => 2018]);
    $this->jobIds[] = $summaryObject->calculate_year_totals();
    $summaryObject->send_letters();
    $this->assertEquals(1, MailFactory::singleton()->getMailer()->count());
    $mailing = MailFactory::singleton()->getMailer()->getMailings()[0];
    $this->assertNotRegExp('/Cancel_or_change_recurring_giving/', $mailing['html']);
  }

  /**
   * Test the render function.
   *
   * @throws \Exception
   */
  public function testRender(): void {
    $contactID = $this->addTestContact([
      'first_name' => 'Bob',
      'preferred_language' => 'en_US',
      'email' => 'bob@example.com',
    ])['id'];
    $contributions = [
      ['receive_date' => '2018-02-02', 'total_amount' => 50],
      ['receive_date' => '2018-03-04', 'total_amount' => 800],
      ['receive_date' => '2018-05-04', 'total_amount' => 50],
      ['receive_date' => '2018-07-13', 'total_amount' => 50],
      ['receive_date' => '2018-01-27', 'total_amount' => 50],
      ['receive_date' => '2018-05-12', 'total_amount' => 800],
      ['receive_date' => '2018-09-03', 'total_amount' => 800],
      ['receive_date' => '2018-12-10', 'total_amount' => 1200],
      ['receive_date' => '2018-12-23', 'total_amount' => 800],
      ['receive_date' => '2018-11-23', 'total_amount' => 800],
      ['receive_date' => '2018-05-06', 'total_amount' => 800],
      ['receive_date' => '2018-06-07', 'total_amount' => 50],
      ['receive_date' => '2018-07-08', 'total_amount' => 50],
      ['receive_date' => '2018-08-09', 'total_amount' => 50],
      ['receive_date' => '2018-06-09', 'total_amount' => 50],
      ['receive_date' => '2018-08-09', 'total_amount' => 50],
      ['receive_date' => '2018-03-03', 'total_amount' => 800],
      ['receive_date' => '2018-06-05', 'total_amount' => 50],
      ['receive_date' => '2018-10-04', 'total_amount' => 100],
      ['receive_date' => '2018-10-10', 'total_amount' => 1200],
      ['receive_date' => '2018-10-12', 'total_amount' => 100],
      ['receive_date' => '2018-10-13', 'total_amount' => 100],
      // Make sure they sort in order with some time.
      ['receive_date' => '2018-10-13 01:01:01', 'total_amount' => 800],
      ['receive_date' => '2018-10-15', 'total_amount' => 50],
      ['receive_date' => '2018-10-16', 'total_amount' => 50],
      ['receive_date' => '2018-10-21', 'total_amount' => 50],
      ['receive_date' => '2018-10-23', 'total_amount' => 50],
    ];
    $this->createRecurringContributions($contactID, $contributions);

    $eoyClass = new EoySummary(['year' => 2018]);
    $eoyClass->calculate_year_totals();
    $eoyClass->send_letters();
    $email = MailFactory::singleton()->getMailer()->getMailings()[0];
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'This is a receipt, but it\'s also so much more',
      'html' => '<p>
Dear Bob,
</p>

<p>
I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.
</p>

<p>
Your donations show us that our work matters, and is worth supporting. Thank you.
</p>

<p>
Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018:
</p>

<p><b>
Your 2018 total was USD 9800.
</b></p>

<p><b>
Donation 1: 50 USD 2018-01-26
</b></p>
<p><b>
Donation 2: 50 USD 2018-02-01
</b></p>
<p><b>
Donation 3: 800 USD 2018-03-02
</b></p>
<p><b>
Donation 4: 800 USD 2018-03-03
</b></p>
<p><b>
Donation 5: 50 USD 2018-05-03
</b></p>
<p><b>
Donation 6: 800 USD 2018-05-05
</b></p>
<p><b>
Donation 7: 800 USD 2018-05-11
</b></p>
<p><b>
Donation 8: 50 USD 2018-06-04
</b></p>
<p><b>
Donation 9: 50 USD 2018-06-06
</b></p>
<p><b>
Donation 10: 50 USD 2018-06-08
</b></p>
<p><b>
Donation 11: 50 USD 2018-07-07
</b></p>
<p><b>
Donation 12: 50 USD 2018-07-12
</b></p>
<p><b>
Donation 13: 50 USD 2018-08-08
</b></p>
<p><b>
Donation 14: 50 USD 2018-08-08
</b></p>
<p><b>
Donation 15: 800 USD 2018-09-02
</b></p>
<p><b>
Donation 16: 100 USD 2018-10-03
</b></p>
<p><b>
Donation 17: 1200 USD 2018-10-09
</b></p>
<p><b>
Donation 18: 100 USD 2018-10-11
</b></p>
<p><b>
Donation 19: 100 USD 2018-10-12
</b></p>
<p><b>
Donation 20: 800 USD 2018-10-12
</b></p>
<p><b>
Donation 21: 50 USD 2018-10-14
</b></p>
<p><b>
Donation 22: 50 USD 2018-10-15
</b></p>
<p><b>
Donation 23: 50 USD 2018-10-20
</b></p>
<p><b>
Donation 24: 50 USD 2018-10-22
</b></p>
<p><b>
Donation 25: 800 USD 2018-11-22
</b></p>
<p><b>
Donation 26: 1200 USD 2018-12-09
</b></p>
<p><b>
Donation 27: 800 USD 2018-12-22
</b></p>

<p>
If for whatever reason you wish to cancel your monthly donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en">easy cancellation instructions</a>.
</p>

<p>With immense gratitude,</p>
<p>
  Katherine<br><br>
  Katherine Maher, Executive Director<br>
  Wikimedia Foundation
</p>

<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703
</p>
',
    ], $email);
  }

  /**
   * Test the render function with donations in multiple currencies.
   *
   * @throws \Exception
   */
  public function testRenderMultiCurrency(): void {
    $contactID = $this->addTestContact([
      'first_name' => 'Bob',
      'preferred_language' => 'en_US',
      'email' => 'bob@example.com',
    ])['id'];
    $contributions = [
      ['receive_date' => '2018-02-02', 'total_amount' => 50],
      ['receive_date' => '2018-03-03', 'total_amount' => 800.00, 'currency' => 'CAD'],
      ['receive_date' => '2018-05-04', 'total_amount' => 20.00],
      ['receive_date' => '2018-10-21', 'total_amount' => 50.00, 'currency' => 'CAD'],
    ];
    $eoyClass = new EoySummary(['year' => 2018]);
    $this->createRecurringContributions($contactID, $contributions);
    $eoyClass->calculate_year_totals();
    $eoyClass->send_letters();
    $email = MailFactory::singleton()->getMailer()->getMailings()[0];
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'This is a receipt, but it\'s also so much more',
      'html' => '<p>
Dear Bob,
</p>

<p>
I am thrilled that this email to you is one of the first things on my to-do list in 2019. Granted, that to-do list is long--Wikipedia has so many amazing projects on the horizon--but thanking you is at the very top.
</p>

<p>
Your donations show us that our work matters, and is worth supporting. Thank you.
</p>

<p>
Here’s a summary of all the donations you made to the Wikimedia Foundation in 2018:
</p>

<p><b>
Your 2018 total was USD 70.
</b></p>
<p><b>
Your 2018 total was CAD 850.
</b></p>

<p><b>
Donation 1: 50 USD 2018-02-01
</b></p>
<p><b>
Donation 2: 800 CAD 2018-03-02
</b></p>
<p><b>
Donation 3: 20 USD 2018-05-03
</b></p>
<p><b>
Donation 4: 50 CAD 2018-10-20
</b></p>

<p>
If for whatever reason you wish to cancel your monthly donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&basic=true&language=en">easy cancellation instructions</a>.
</p>

<p>With immense gratitude,</p>
<p>
  Katherine<br><br>
  Katherine Maher, Executive Director<br>
  Wikimedia Foundation
</p>

<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703
</p>
',
    ], $email);
  }

  public function setUpContactsSharingEmail(): array {
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
    $completedStatusId = CRM_Core_PseudoConstant::getKey(
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
               $contribCashRecurringOlder,
             ] as $contrib) {
      $this->ids['Contribution'][$contrib['id']] = $contrib['id'];
    }
    return [$olderContact['id'], $newerContact['id']];
  }

  protected function addTestContact($params = []) {
    $contact = $this->callAPISuccess('Contact', 'create', array_merge([
      'first_name' => 'Jimmy',
      'last_name' => 'Walrus',
      'contact_type' => 'Individual',
      'email' => 'jimmy@example.com',
      'preferred_language' => 'en_US',
    ], $params));
    $this->ids['Contact'][$contact['id']] = $contact['id'];
    return $contact;
  }

  protected function addTestContactContribution($contact_id, $params = []) {
    $financialTypeCash = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash'
    );
    $completedStatusId = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'
    );

    $contribution = $this->callAPISuccess('Contribution', 'create', array_merge([
      'receive_date' => date("Y-m-d H:i:s"),
      'contact_id' => $contact_id,
      'total_amount' => '10',
      'currency' => 'USD',
      'contribution_status_id' => $completedStatusId,
      'financial_type_id' => $financialTypeCash,
    ], $params));

    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    return $contribution;
  }

  /**
   * @param int $jobId
   * @param string $email
   *
   * @return mixed
   */
  protected function getWMFReceiptDonorRows(int $jobId, string $email) {
    $sql = <<<EOS
SELECT *
FROM wmf_eoy_receipt_donor
WHERE
  status = 'queued'
  AND job_id = $jobId
  AND email = %1
EOS;
    $result = CRM_Core_DAO::executeQuery($sql, [1 => [$email, 'String']]);
    return $result->fetchAll()[0] ?? NULL;
  }

  /**
   * Cleanup a contact record.
   *
   * @param int $contactId
   */
  public function cleanUpContact(int $contactId): void {
    $contributions = $this->callAPISuccess('Contribution', 'get', [
      'contact_id' => $contactId,
      'options' => ['limit' => 0],
    ])['values'];
    if (!empty($contributions)) {
      foreach ($contributions as $id => $details) {
        $this->callAPISuccess('Contribution', 'delete', [
          'id' => $id,
        ]);
      }
    }
    $this->callAPISuccess('Contact', 'delete', [
      'id' => $contactId,
      'skip_undelete' => TRUE,
    ]);
  }

  /**
   * @param $contactID
   * @param array $contributions
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createRecurringContributions($contactID, array $contributions): void {
    $this->ids['ContributionRecur'][0] = ContributionRecur::create(FALSE)
      ->setValues([
        'contact_id' => $contactID,
        'amount' => 50,
        'financial_type_id:name' => 'Donation',
      ])
      ->execute()
      ->first()['id'];

    foreach ($contributions as $contribution) {
      $this->ids['Contribution'][] = Contribution::create(FALSE)
        ->setValues(array_merge([
          'contact_id' => $contactID,
          'financial_type_id:name' => 'Donation',
          'currency' => 'USD',
          'contribution_recur_id' => $this->ids['ContributionRecur'][0],
        ], $contribution))
        ->execute()
        ->first()['id'];
    }
  }

}

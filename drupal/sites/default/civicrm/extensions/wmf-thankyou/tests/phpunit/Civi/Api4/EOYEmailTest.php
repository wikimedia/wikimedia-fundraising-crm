<?php
namespace Civi\Api4;

use Civi\Omnimail\MailFactory;
use Civi\Test\Api3TestTrait;
use CRM_Contribute_WorkflowMessage_EOYThankYou;
use CRM_Core_DAO;
use CRM_Core_PseudoConstant;
use PHPUnit\Framework\TestCase;
use Civi\EoySummary;
use Civi\Test\Mailer;

class EOYEmailTest extends TestCase {

  use Api3TestTrait;

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
    CRM_Core_DAO::executeQuery("DELETE from wmf_eoy_receipt_donor WHERE year = 2018");
    foreach ($this->ids as $entity => $entityIDs) {
      foreach ($entityIDs as $entityID) {
        if ($entity === 'Contact') {
          $this->cleanUpContact($entityID);
        }
        else {
          try {
            civicrm_api3($entity, 'delete', ['id' => $entityID]);
          }
          catch (\CiviCRM_API3_Exception $e) {
            // best effort, move along.
          }
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
    $this->ids['Contact']['suzuki'] = Contact::create()
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
            'total_amount' => 50,
            'currency' => 'USD',
            'receive_date' => '2020-08-06',
            'financial_type_id:name' => 'Donation',
            'contribution_extra.original_currency' => 'JPY',
            'contribution_extra.original_amount' => 5,
          ])
          ->addValue('contact_id', '$id')
      )->execute()->first()['id'];

    $message = EOYEmail::render()->setCheckPermissions(FALSE)
      ->setYear(2020)->setContactID($this->ids['Contact']['suzuki'])->execute()->first();
    $this->assertContains('Benvolgut/Benvolguda 鈴木', $message['html']);
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

    $originalCurrencyField = wmf_civicrm_get_custom_field_name('original_currency');
    $originalAmountField = wmf_civicrm_get_custom_field_name('original_amount');
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2017-12-31 22:59:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '10',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '100',
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01', // FIXME: edge case found?
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-08-08 22:00:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-01-01',
      'contact_id' => $contactOnetime['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '200',
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-02-02 08:10:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      $originalCurrencyField => 'USD',
      $originalAmountField => '20',
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-03-03',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '21',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '210',
      'contribution_status_id' => 'Refunded',
      'financial_type_id' => 'Cash',
    ]);
    $this->callAPISuccess('Contribution', 'create', [
      'receive_date' => '2018-04-04',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      $originalCurrencyField => 'PLN',
      $originalAmountField => '400',
      'contribution_status_id' => 'Completed',
      'financial_type_id' => 'Endowment Gift',
    ]);

    EOYEmail::makeJob(FALSE)->setYear(2018)->execute();

    $result = $this->getWMFReceiptDonorRows(2018, 'onetime@walrus.org');
    $this->assertEmpty($result);
    $result = $this->getWMFReceiptDonorRows(2018, 'recurring@rabbit.org');

    $this->assertEquals('recurring@rabbit.org', $result['email']);
    $this->assertEquals('queued', $result['status']);
    $this->assertEquals(2018, $result['year']);
    $contactIDs = [$contactRecur['id']];
    $totals = [
      'USD' => [
        'amount' => '20,00',
        'currency' => 'USD',
      ],
      'PLN' => [
        'amount' => '430,00',
        'currency' => 'PLN',
      ],
    ];
    $this->assertTemplateCalculations($contactIDs, $totals, [
      1 => [
        'contribution_extra.original_currency' => 'USD',
        'financial_type_id:name' => 'Cash',
        'contribution_extra.original_amount' => 20.0,
        'contribution_recur_id' => NULL,
        'receive_date' => '2018-02-01',
        'total_amount' => 20.0,
        'currency' => 'USD',
        'financial_type' => 'Cash',
        'amount' => '20.00',
        'date' => '2018-02-01',
      ],
      2 => [
        'contribution_extra.original_currency' => 'PLN',
        'financial_type_id:name' => 'Endowment Gift',
        'contribution_extra.original_amount' => 400.0,
        'contribution_recur_id' => NULL,
        'receive_date' => '2018-04-03',
        'total_amount' => 400.0,
        'currency' => 'PLN',
        'financial_type' => 'Endowment Gift',
        'amount' => '400.00',
        'date' => '2018-04-03',
      ],
      3 => [
        'contribution_extra.original_currency' => 'PLN',
        'financial_type_id:name' => 'Cash',
        'contribution_extra.original_amount' => 30.0,
        'contribution_recur_id' => $recurring['id'],
        'receive_date' => '2018-08-08',
        'total_amount' => 30.0,
        'currency' => 'PLN',
        'financial_type' => 'Cash',
        'amount' => '30.00',
        'date' => '2018-08-08',
      ],
    ]);
  }

  /**
   * Test that we include contributions from two contact records with the same
   * email when one of them has a recurring contribution.
   *
   * @throws \API_Exception
   */
  public function testCalculateDedupe(): void {
    $this->setUpContactsSharingEmail();
    EOYEmail::makeJob(FALSE)->setYear(2018)->execute();
    $result = $this->getWMFReceiptDonorRows(2018, 'goat@wbaboxing.com');

    $this->assertEquals('goat@wbaboxing.com', $result['email']);
    $this->assertEquals(2018, $result['year']);
    $this->assertEquals('queued', $result['status']);
    $this->assertTemplateCalculations($this->ids['Contact'], [
      'PLN' => [
        'amount' => '630,00',
        'currency' => 'PLN',
      ],
    ], [
      1 => [
          'contribution_extra.original_currency' => 'PLN',
          'financial_type_id:name' => 'Cash',
          'contribution_extra.original_amount' => 400.0,
          'contribution_recur_id' => null,
          'receive_date' => '2018-02-01',
          'total_amount' => 400.0,
          'currency' => 'PLN',
          'financial_type' => 'Cash',
          'amount' => '400.00',
          'date' => '2018-02-01',
      ],
      2 => [
          'contribution_extra.original_currency' => 'PLN',
        'financial_type_id:name' => 'Cash',
        'contribution_extra.original_amount' => 30.0,
        'contribution_recur_id' => reset($this->ids['ContributionRecur']),
        'receive_date' => '2018-03-02',
        'total_amount' => 30.0,
        'currency' => 'PLN',
        'financial_type' => 'Cash',
        'amount' => '30.00',
        'date' => '2018-03-02',
      ],
      3 => [
        'contribution_extra.original_currency' => 'PLN',
        'financial_type_id:name' => 'Cash',
        'contribution_extra.original_amount' => 200.0,
        'contribution_recur_id' => NULL,
        'receive_date' => '2018-04-03',
        'total_amount' => 200.0,
        'currency' => 'PLN',
        'financial_type' => 'Cash',
        'amount' => '200.00',
        'date' => '2018-04-03',
      ],
    ]);
  }

  /**
   * @throws \API_Exception
   */
  public function testCalculateSingleContactId(): void {
    $contact = $this->addTestContact(['email' => 'jimmysingle@example.com']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-27 22:59:00']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-28 22:59:00']);

    $email = EOYEmail::render(FALSE)->setYear(2019)->setContactID($contact['id'])->execute();
    $this->assertCount(1, $email);
    $this->assertEquals([
      'to_name' => 'Jimmy Walrus',
      'to_address' => 'jimmysingle@example.com',
      'subject' => 'A record of your support for Wikipedia',
      'html' => "<p>
Dear Jimmy,
</p>

<p>
This past year, we’ve kept meticulous track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made in 2019:
</p>

<p><b>
  Your 2019 total was USD 20.00.
</b></p>
<p><b>Total donations to Wikimedia Foundation:</b></p>
<p>
  Donation 1: 10.00 USD on 2019-11-27
</p>
<p>
  Donation 2: 10.00 USD on 2019-11-28
</p>

<p><b>Total donations to Wikimedia Endowment:</b></p>



<p>With gratitude,</p>
<p>
The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation's free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href=\"mailto:legacy@wikimedia.org\">Contact us to learn how to make a legacy gift.</a></p>
<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
",
    ], $email->first());
  }

  /**
   * Test that we create activity records for each contact with a
   * shared email.
   *
   * @throws \API_Exception
   */
  public function testCreateActivityRecords(): void {
    $contactIds = $this->setUpContactsSharingEmail();
    $mailing = $this->send();
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
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
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
    $mailing = $this->send();
    $this->assertNotRegExp('/Cancel_or_change_recurring_giving/', $mailing['html']);
  }

  /**
   * @throws \API_Exception
   */
  public function testSendSpecifiedContactOnly(): void {
    // Set up some contacts that should NOT be mailed.
    $this->setUpContactsSharingEmail();
    // Set up the contact to email.
    $contact = $this->addTestContact(['email' => 'jimmysingle@example.com']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2018-11-27 22:59:00']);
    $this->send(2018, $contact['id']);
    $this->assertEquals(1, MailFactory::singleton()->getMailer()->count());
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

    $email = $this->send();
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob Walrus',
      'to_address' => 'bob@example.com',
      'subject' => 'A record of your support for Wikipedia',
      'html' => '<p>
Dear Bob,
</p>

<p>
This past year, we’ve kept meticulous track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made in 2018:
</p>

<p><b>
  Your 2018 total was USD 9,800.00.
</b></p>
<p><b>Total donations to Wikimedia Foundation:</b></p>
<p>
  Donation 1: 50.00 USD on 2018-01-26
</p>
<p>
  Donation 2: 50.00 USD on 2018-02-01
</p>
<p>
  Donation 3: 800.00 USD on 2018-03-02
</p>
<p>
  Donation 4: 800.00 USD on 2018-03-03
</p>
<p>
  Donation 5: 50.00 USD on 2018-05-03
</p>
<p>
  Donation 6: 800.00 USD on 2018-05-05
</p>
<p>
  Donation 7: 800.00 USD on 2018-05-11
</p>
<p>
  Donation 8: 50.00 USD on 2018-06-04
</p>
<p>
  Donation 9: 50.00 USD on 2018-06-06
</p>
<p>
  Donation 10: 50.00 USD on 2018-06-08
</p>
<p>
  Donation 11: 50.00 USD on 2018-07-07
</p>
<p>
  Donation 12: 50.00 USD on 2018-07-12
</p>
<p>
  Donation 13: 50.00 USD on 2018-08-08
</p>
<p>
  Donation 14: 50.00 USD on 2018-08-08
</p>
<p>
  Donation 15: 800.00 USD on 2018-09-02
</p>
<p>
  Donation 16: 100.00 USD on 2018-10-03
</p>
<p>
  Donation 17: 1,200.00 USD on 2018-10-09
</p>
<p>
  Donation 18: 100.00 USD on 2018-10-11
</p>
<p>
  Donation 19: 100.00 USD on 2018-10-12
</p>
<p>
  Donation 20: 800.00 USD on 2018-10-12
</p>
<p>
  Donation 21: 50.00 USD on 2018-10-14
</p>
<p>
  Donation 22: 50.00 USD on 2018-10-15
</p>
<p>
  Donation 23: 50.00 USD on 2018-10-20
</p>
<p>
  Donation 24: 50.00 USD on 2018-10-22
</p>
<p>
  Donation 25: 800.00 USD on 2018-11-22
</p>
<p>
  Donation 26: 1,200.00 USD on 2018-12-09
</p>
<p>
  Donation 27: 800.00 USD on 2018-12-22
</p>

<p><b>Total donations to Wikimedia Endowment:</b></p>


<p>
  If you’d like to cancel your monthly donation, follow these easy <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&amp;basic=true&amp;language=en">easy cancellation instructions</a>.
</p>

<p>With gratitude,</p>
<p>
The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation\'s free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href="mailto:legacy@wikimedia.org">Contact us to learn how to make a legacy gift.</a></p>
<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
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
    $this->createRecurringContributions($contactID, $contributions);
    $email = $this->send();
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob Walrus',
      'to_address' => 'bob@example.com',
      'subject' => 'A record of your support for Wikipedia',
      'html' => '<p>
Dear Bob,
</p>

<p>
This past year, we’ve kept meticulous track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made in 2018:
</p>

<p><b>
  Your 2018 total was USD 70.00.
</b></p>
<p><b>
  Your 2018 total was CAD 850.00.
</b></p>
<p><b>Total donations to Wikimedia Foundation:</b></p>
<p>
  Donation 1: 50.00 USD on 2018-02-01
</p>
<p>
  Donation 2: 800.00 CAD on 2018-03-02
</p>
<p>
  Donation 3: 20.00 USD on 2018-05-03
</p>
<p>
  Donation 4: 50.00 CAD on 2018-10-20
</p>

<p><b>Total donations to Wikimedia Endowment:</b></p>


<p>
  If you’d like to cancel your monthly donation, follow these easy <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&amp;basic=true&amp;language=en">easy cancellation instructions</a>.
</p>

<p>With gratitude,</p>
<p>
The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation\'s free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href="mailto:legacy@wikimedia.org">Contact us to learn how to make a legacy gift.</a></p>
<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
',
    ], $email);
  }

  /**
   * Test the render function falls back to the best language choice.
   *
   * We should fall back to Spanish for oddballs like 'es_NO' which
   * our database delights in having.
   *
   * @throws \API_Exception
   */
  public function testRenderHalfBakedLanguage(): void {
    $this->ids['OptionValue'][] = OptionValue::create(FALSE)->setValues([
      'option_group_id.name' => 'languages',
      'name' => 'es_NO',
      'value' => 'es',
      'label' => 'Norwegian Spanish (of course)',
    ])->execute()->first()['id'];
    $contactID = $this->addTestContact([
      'first_name' => 'Bob',
      'preferred_language' => 'es_NO',
      'email' => 'bob@example.com',
    ])['id'];
    $this->createRecurringContributions($contactID, [['receive_date' => '2018-02-02', 'total_amount' => 50]]);
    $email = $this->send();
    $this->assertEquals('Un resumen de su apoyo a Wikipedia', $email['subject']);
    $this->assertStringContainsString('¡Hola, Bob!', $email['html']);
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
   * @param int $year
   * @param string $email
   *
   * @return mixed
   */
  protected function getWMFReceiptDonorRows(int $year, string $email) {
    $result = CRM_Core_DAO::executeQuery("SELECT *
FROM wmf_eoy_receipt_donor
WHERE
  status = 'queued'
  AND year = $year
  AND email = %1", [1 => [$email, 'String']]);
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
      $contribution['contribution_extra.original_amount'] = $contribution['total_amount'];
      $contribution['contribution_extra.original_currency'] = $contribution['currency'] ?? 'USD';
      $contribution['currency'] = 'USD';
      $this->ids['Contribution'][] = Contribution::create(FALSE)
        ->setValues(array_merge([
          'contact_id' => $contactID,
          'financial_type_id:name' => 'Donation',
          'contribution_recur_id' => $this->ids['ContributionRecur'][0],
        ], $contribution))
        ->execute()
        ->first()['id'];
    }
  }

  /**
   * Get the first sent email.
   *
   * @return array
   */
  protected function getFirstEmail(): array {
    return MailFactory::singleton()->getMailer()->getMailings()[0];
  }

  /**
   * @param array $contactIDs
   * @param array $totals
   * @param array $contributions
   *
   * @throws \API_Exception
   */
  protected function assertTemplateCalculations(array $contactIDs, array $totals, array $contributions): void {
    $template = new CRM_Contribute_WorkflowMessage_EOYThankYou();
    $template->setContactIDs($contactIDs);
    $template->setLocale('pt_BR');
    $template->setYear(2018);
    $this->assertEquals($totals, $template->getTotals());
    $calculatedContributions = $template->getContributions();
    foreach ($calculatedContributions as &$contribution) {
      unset($contribution['id']);
    }
    unset($contribution);
    $this->assertEquals($contributions, $calculatedContributions);
  }

  /**
   * Send the email/s.
   *
   * @param int $year
   * @param int|null $contactID
   *
   * @return array
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function send(int $year = 2018, ?int $contactID = NULL): array {
    EOYEmail::makeJob(FALSE)->setYear($year)->setContactID($contactID)->execute();
    EOYEmail::send(FALSE)->setYear($year)->setContactID($contactID)->execute();
    $this->assertEquals(1, MailFactory::singleton()->getMailer()->count());
    return $this->getFirstEmail();
  }

}

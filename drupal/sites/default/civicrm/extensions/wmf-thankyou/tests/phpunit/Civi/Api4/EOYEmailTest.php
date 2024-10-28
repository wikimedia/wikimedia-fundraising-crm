<?php
namespace Civi\Api4;

use Civi\Omnimail\MailFactory;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\Test\Mailer;
use Civi\WorkflowMessage\EOYThankYou;
use CRM_Core_DAO;
use PHPUnit\Framework\TestCase;

class EOYEmailTest extends TestCase {
  use EntityTrait;
  use Api3TestTrait;

  protected $oldFromName;
  protected $oldFromAddress;

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
    $this->oldFromName = \Civi::settings()->get('wmf_eoy_thank_you_from_name');
    $this->oldFromAddress = \Civi::settings()->get('wmf_eoy_thank_you_from_address');
    \Civi::settings()->set('wmf_eoy_thank_you_from_name', 'Bobita');
    \Civi::settings()->set('wmf_eoy_thank_you_from_address', 'bobita@example.org');
    $mailfactory = MailFactory::singleton();
    $mailfactory->setActiveMailer(NULL, new Mailer());
  }

  /**
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    \Civi::settings()->set('wmf_eoy_thank_you_from_name', $this->oldFromName);
    \Civi::settings()->set('wmf_eoy_thank_you_from_address', $this->oldFromAddress);
    CRM_Core_DAO::executeQuery("DELETE from wmf_eoy_receipt_donor WHERE year = 2018");
    if (!empty($this->ids['Contact'])) {
      Contribution::delete(FALSE)->addWhere('contact_id', 'IN', $this->ids['Contact'])->execute();
      ContributionRecur::delete(FALSE)->addWhere('contact_id', 'IN', $this->ids['Contact'])->execute();
      Contact::delete(FALSE)->addWhere('id', 'IN', $this->ids['Contact'])->setUseTrash(FALSE)->execute();
    }
    if (!empty($this->ids['OptionValue'])) {
      OptionValue::delete(FALSE)->addWhere('id', 'IN', $this->ids['OptionValue'])->execute();
    }
    parent::tearDown();
  }

  /**
   * Test rendering an EOY email without a specific year set.
   *
   * @return void
   */
  public function testRenderNoYear(): void {
    $contact = $this->setupEOYRecipient();

    $email = (array) EOYEmail::render(FALSE)
      ->setContactID($contact['id'])
      ->execute()->first();
    // This text would not start with a capital W if the year token were present.
    $this->assertStringContainsString("We've kept track", $email['html']);
    // Since it's html the double space will not render, but it happens where the year would - but
    $this->assertStringContainsString('Your  total was USD 30.00.', $email['html']);

    // This date range will just get the last 2 - ie $20
    $email = (array) EOYEmail::render(FALSE)
      ->setContactID($contact['id'])
      ->setStartDateTime('2019-11-28 22:58:00')
      ->execute()->first();
    $this->assertStringContainsString('Your  total was USD 20.00.', $email['html']);
    $this->assertStringContainsString('between November 28th, 2019 10:58 PM and October 30th, 2024 11:59 PM', $email['html']);

    // This date range will just get the middle 1 - ie $10
    $email = (array) EOYEmail::render(FALSE)
      ->setContactID($contact['id'])
      ->setStartDateTime('2019-11-28 22:58:00')
      ->setEndDateTime('2019-11-28 23:58:00')
      ->execute()->first();
    $this->assertStringContainsString('Your  total was USD 10.00.', $email['html']);

    try {
      // This date range will get no results.
      EOYEmail::render(FALSE)
        ->setContactID($contact['id'])
        ->setDateRelative('this.year')
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertEquals('No contributions in the given time from - ' . date('Y') . '0101 to ' . date('Y') . '1231 for contact/s ' . $contact['id'], $e->getMessage());
      return;
    }
    $this->fail('an exception was expected');

  }

  /**
   * Test that Japanese characters in a name are rendered correctly.
   *
   * We no longer use the Japanese template as the name is not
   * in it due to https://phabricator.wikimedia.org/T271189
   *
   * @throws \CRM_Core_Exception
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
    $this->assertStringContainsString('Benvolgut/Benvolguda 鈴木', $message['html']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testCalculate(): void {
    $contactOnetime = $this->createTestEntity('Contact', [
      'first_name' => 'Onetime',
      'last_name' => 'walrus',
      'contact_type' => 'Individual',
      'email_primary.email' => 'onetime@walrus.org',
    ], 'walrus');
    $contactRecur = $this->createTestEntity('Contact', [
      'first_name' => 'Recurring',
      'last_name' => 'Rabbit',
      'contact_type' => 'Individual',
      'email_primary.email' => 'recurring@rabbit.org',
      'preferred_language' => 'pt_BR',
    ], 'rabbit');

    $processor = $this->callAPISuccessGetSingle('PaymentProcessor', [
      'name' => 'ingenico',
      'is_test' => 1,
    ]);
    $recurring = $this->createTestEntity('ContributionRecur', [
      'contact_id' => $contactRecur['id'],
      'amount' => 200,
      'currency' => 'PLN',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'trxn_id' => 678,
      'payment_processor_id' => $processor['id'],
    ]);
    $this->ids['ContributionRecur'][$recurring['id']] = $recurring['id'];

    $this->createTestEntity('Contribution', [
      'receive_date' => '2017-12-31 22:59:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '10',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '100',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      // FIXME: edge case found?
      'receive_date' => '2018-01-01',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '200',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-08-08 22:00:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-01-01',
      'contact_id' => $contactOnetime['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '200',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-02-02 08:10:00',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'USD',
      'contribution_extra.original_amount' => '20',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-03-03',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '21',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '210',
      'contribution_status_id:name' => 'Refunded',
      'financial_type_id:name' => 'Cash',
    ]);
    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-04-04',
      'contact_id' => $contactRecur['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '400',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Endowment Gift',
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
        'amount' => '20,00',
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
        'amount' => '400,00',
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
        'amount' => '30,00',
        'date' => '2018-08-08',
      ],
    ]);
  }

  /**
   * Test that we include contributions from two contact records with the same
   * email when one of them has a recurring contribution.
   *
   * @throws \CRM_Core_Exception
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
        'contribution_recur_id' => NULL,
        'receive_date' => '2018-02-01',
        'total_amount' => 400.0,
        'currency' => 'PLN',
        'financial_type' => 'Cash',
        'amount' => '400,00',
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
        'amount' => '30,00',
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
        'amount' => '200,00',
        'date' => '2018-04-03',
      ],
    ]);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testCalculateSingleContactId(): void {
    $contact = $this->setupEOYRecipient();

    $email = EOYEmail::render(FALSE)->setYear(2019)->setContactID($contact['id'])->execute();
    $this->assertCount(1, $email);
    $this->assertEquals([
      'to_name' => 'Jimmy Walrus',
      'to_address' => 'jimmysingle@example.com',
      'subject' => 'A record of your support for Wikipedia',
      'contactIDs' => [$contact['id']],
      'html' => "<img alt=\"Wikimedia Foundation\" src=\"https://upload.wikimedia.org/wikipedia/commons/thumb/0/09/Wikimedia_Foundation_logo_-_horizontal.svg/320px-Wikimedia_Foundation_logo_-_horizontal.svg.png\" width=\"150\" style=\"display: block; width: 30%; margin: auto;\" />
<p>
    Dear Jimmy,
  </p>

<p>
  This past year, we’ve kept track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
  Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made
  in 2019
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



<p>With gratitude,</p>
<p>
  The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation's free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href=\"mailto:legacy@wikimedia.org\">Contact us to learn how to make a legacy gift.</a></p>
<p>Jimmy Walrus, this letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
<p>CNTCT-{$contact['id']}</p>
<!-- TI_BEGIN[“name”:“End_of_Year.en.html”,“revision”:20230331,“currency”:““]TI_END -->
",
    ], $email->first());
  }

  /**
   * Test that we create activity records for each contact with a
   * shared email.
   *
   */
  public function testCreateActivityRecords(): void {
    $contactIds = $this->setUpContactsSharingEmail();
    $mailing = $this->send();
    $this->assertMatchesRegularExpression('/Cancel_or_change_recurring_giving/', $mailing['html']);
    $firstContactID = reset($contactIds);
    $activity = $this->callAPISuccessGetSingle('Activity', [
      'activity_type_id' => 'wmf_eoy_receipt_sent',
      'target_contact_id' => $firstContactID,
      'return' => ['target_contact_id', 'subject', 'details'],
    ]);

    $this->assertEquals(
      'Sent contribution summary receipt for year 2018 to goat@wbaboxing.com',
      $activity['subject']
    );
    $this->assertEquals($mailing['html'], $activity['details']);
    $this->assertEquals($contactIds, $activity['target_contact_id']);
  }

  /**
   * Test that we don't include cancellation instructions for
   * donors whose donation is already cancelled.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSendWithRecurringDonationsCancelled(): void {
    $this->setUpContactsSharingEmail();
    ContributionRecur::update(FALSE)
      ->addWhere('id', 'IN', $this->ids['ContributionRecur'])
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->execute();
    $mailing = $this->send();
    $this->assertDoesNotMatchRegularExpression('/Cancel_or_change_recurring_giving/', $mailing['html']);
  }

  /**
   * @throws \CRM_Core_Exception
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
   * @throws \CRM_Core_Exception
   */
  public function testRender(): void {
    $contactID = $this->addTestContact([
      'first_name' => 'Bob',
      'preferred_language' => 'en_US',
      'email_primary.email' => 'bob@example.com',
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
      'contactIDs' => [$contactID],
      'html' => '<img alt="Wikimedia Foundation" src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/09/Wikimedia_Foundation_logo_-_horizontal.svg/320px-Wikimedia_Foundation_logo_-_horizontal.svg.png" width="150" style="display: block; width: 30%; margin: auto;" />
<p>
    Dear Bob,
  </p>

<p>
  This past year, we’ve kept track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
  Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made
  in 2018
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


<p>
  If you’d like to update or cancel your monthly donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&amp;basic=true&amp;language=en">easy instructions</a>.
</p>

<p>With gratitude,</p>
<p>
  The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation\'s free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href="mailto:legacy@wikimedia.org">Contact us to learn how to make a legacy gift.</a></p>
<p>Bob Walrus, this letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
<p>CNTCT-' . $contactID . '</p>
<!-- TI_BEGIN[“name”:“End_of_Year.en.html”,“revision”:20230331,“currency”:““]TI_END -->
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
      'email_primary.email' => 'bob@example.com',
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
      'contactIDs' => [$contactID],
      'subject' => 'A record of your support for Wikipedia',
      'html' => '<img alt="Wikimedia Foundation" src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/09/Wikimedia_Foundation_logo_-_horizontal.svg/320px-Wikimedia_Foundation_logo_-_horizontal.svg.png" width="150" style="display: block; width: 30%; margin: auto;" />
<p>
    Dear Bob,
  </p>

<p>
  This past year, we’ve kept track of the generous contributions you made in support of Wikipedia, not only because we’re extremely grateful, but also because we knew you’d appreciate having a copy of this record. This includes gifts to the Wikimedia Foundation as well as gifts to the Wikimedia Endowment, if any.
</p>
<p>
  Thank you for demonstrating your support for our mission to make free and reliable information accessible to everyone in the world. Here’s a summary of the donations you made
  in 2018
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


<p>
  If you’d like to update or cancel your monthly donation, follow these <a href="https://donate.wikimedia.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_giving&amp;basic=true&amp;language=en">easy instructions</a>.
</p>

<p>With gratitude,</p>
<p>
  The Wikimedia Foundation
</p>

<p>The Wikimedia Endowment ensures Wikimedia Foundation\'s free knowledge resources remain accessible and valuable for generations to come.</p>
<p>Help ensure the future is filled with curiosity and wonder by remembering Wikipedia in your will. <a href="mailto:legacy@wikimedia.org">Contact us to learn how to make a legacy gift.</a></p>
<p>Bob Walrus, this letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. Our postal address is: Wikimedia Foundation, Inc., P.O. Box 98204, Washington, DC 20090-8204, USA. U.S. tax-exempt number: 20-0049703</p>
<p>CNTCT-' . $contactID . '</p>
<!-- TI_BEGIN[“name”:“End_of_Year.en.html”,“revision”:20230331,“currency”:““]TI_END -->
',
    ], $email);
  }

  /**
   * Test the render function falls back to the best language choice.
   *
   * We should fall back to Spanish for oddballs like 'es_NZ' which
   * our database delights in having.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRenderHalfBakedLanguage(): void {
    $this->createTestEntity('OptionValue', [
      'option_group_id.name' => 'languages',
      'name' => 'es_NZ',
      'value' => 'es',
      'label' => 'Kiwi Spanish (of course)',
    ], 'kiwi');
    $contactID = $this->addTestContact([
      'first_name' => 'Bob',
      'preferred_language' => 'es_NZ',
      'email_primary.email' => 'bob@example.com',
    ])['id'];
    $this->createRecurringContributions($contactID, [['receive_date' => '2018-02-02', 'total_amount' => 50]]);
    $email = $this->send();
    $this->assertEquals('Registro de tu apoyo a Wikipedia', $email['subject']);
    $this->assertStringContainsString('¡Hola, Bob!', $email['html']);
  }

  /**
   * Test that bad characters in names don't break our templates (notably quotes).
   *
   * @dataProvider getBadNames
   */
  public function testBadNames($contactParams, $salutation): void {
    $contact = $this->addTestContact($contactParams);
    $this->addTestContactContribution($contact['id']);
    $email = $this->send(date('Y'), $contact['id']);
    $this->assertEquals('A record of your support for Wikipedia', $email['subject']);
    if ($salutation) {
      $this->assertStringContainsString($salutation, $email['html']);
    }
    else {
      $this->assertStringNotContainsString('Dear', $email['html']);
    }
  }

  public function getBadNames(): array {
    return [
      [['first_name' => 'Bob', 'last_name' => "O'Riley"], 'Dear Bob'],
      [['first_name' => 'Bob', 'last_name' => ''], NULL],
      [['first_name' => "D'Artagnan", 'last_name' => ''], NULL],
      [['first_name' => "D'Artagnan", 'last_name' => 'Smith'], "Dear D&#039;Artagnan"],
    ];
  }

  public function setUpContactsSharingEmail(): array {
    $olderContact = $this->createTestEntity('Contact', [
      'first_name' => 'Cassius',
      'last_name' => 'Clay',
      'contact_type' => 'Individual',
      'email_primary.email' => 'goat@wbaboxing.com',
      'preferred_language' => 'en_US',
    ], 'older');
    $newerContact = $this->createTestEntity('Contact', [
      'first_name' => 'Muhammad',
      'last_name' => 'Ali',
      'contact_type' => 'Individual',
      'email_primary.email' => 'goat@wbaboxing.com',
      'preferred_language' => 'ar_EG',
    ], 'newer');

    $processor = $this->callAPISuccessGetSingle('PaymentProcessor', [
      'name' => 'ingenico',
      'is_test' => 1,
    ]);
    $recurring = $this->createTestEntity('ContributionRecur', [
      'contact_id' => $olderContact['id'],
      'amount' => 200,
      'currency' => 'PLN',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'trxn_id' => mt_rand(),
      'payment_processor_id' => $processor['id'],
    ]);

    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-02-02',
      'contact_id' => $olderContact['id'],
      'total_amount' => '40',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '400',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ], 'cash_older');

    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-03-03',
      'contact_id' => $olderContact['id'],
      'total_amount' => '3',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '30',
      'contribution_recur_id' => $recurring['id'],
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ], 'recurring_older');

    $this->createTestEntity('Contribution', [
      'receive_date' => '2018-04-04',
      'contact_id' => $newerContact['id'],
      'total_amount' => '20',
      'currency' => 'USD',
      'contribution_extra.original_currency' => 'PLN',
      'contribution_extra.original_amount' => '200',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ], 'recurring_newer');
    return [$this->ids['Contact']['older'], $this->ids['Contact']['newer']];
  }

  protected function addTestContact(array $params = []): array {
    return $this->createTestEntity('Contact', array_merge([
      'first_name' => 'Jimmy',
      'last_name' => 'Walrus',
      'contact_type' => 'Individual',
      'email_primary.email' => 'jimmy@example.com',
      'preferred_language' => 'en_US',
    ], $params), 'jimmy');
  }

  /**
   * At a contribution for the contact.
   *
   * @param int $contact_id
   * @param array $params
   *
   * @return array
   */
  protected function addTestContactContribution(int $contact_id, array $params = []): array {
    return $this->createTestEntity('Contribution', array_merge([
      'receive_date' => date("Y-m-d H:i:s"),
      'contact_id' => $contact_id,
      'total_amount' => '10',
      'currency' => 'USD',
      'contribution_status_id:name' => 'Completed',
      'financial_type_id:name' => 'Cash',
    ], $params));
  }

  /**
   * @param int $year
   * @param string $email
   *
   * @return mixed
   * @throws \Civi\Core\Exception\DBQueryException
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
   * @throws \CRM_Core_Exception
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
   * @throws \CRM_Core_Exception
   */
  protected function assertTemplateCalculations(array $contactIDs, array $totals, array $contributions): void {
    $template = new EOYThankYou();
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
   */
  protected function send(int $year = 2018, ?int $contactID = NULL): array {
    try {
      EOYEmail::makeJob(FALSE)->setYear($year)->execute();
      EOYEmail::send(FALSE)
        ->setYear($year)
        ->setContactID($contactID)
        ->execute();
      $this->assertEquals(1, MailFactory::singleton()->getMailer()->count());
      return $this->getFirstEmail();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to send ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
  }

  /**
   * @return array
   */
  public function setupEOYRecipient(): array {
    $contact = $this->addTestContact(['email_primary.email' => 'jimmysingle@example.com']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-27 22:59:00']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2019-11-28 22:59:00']);
    $this->addTestContactContribution($contact['id'], ['receive_date' => '2020-11-28 22:59:00']);
    return $contact;
  }

}

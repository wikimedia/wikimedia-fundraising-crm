<?php
declare(strict_types=1);

namespace Civi\Api4;

use Civi;
use Civi\Test\Api3TestTrait;
use Civi\Test\EntityTrait;
use Civi\WMFEnvironmentTrait;
use CRM_Core_PseudoConstant;
use PHPUnit\Framework\TestCase;
use Civi\Omnimail\MailFactory;

class ThankYouTest extends TestCase {

  use WMFEnvironmentTrait;
  use EntityTrait;
  use Api3TestTrait;

  /**
   * IDS of various entities created.
   *
   * @var array
   */
  protected $ids = [];

  protected array $testContact = [
    'contact_type' => 'Individual',
    'email_primary.email' => 'generousdonor@example.org',
    'city' => 'Somerville',
    'country_id:name' => 'US',
    'postal_code' => '02144',
    'state_province_id:name' => 'MA',
    'street_address' => '1 Davis Square',
    'first_name' => 'Test',
    'last_name' => 'Contact',
    'preferred_language' => 'en_US',
  ];

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
    parent::setUp();
    MailFactory::singleton()->setActiveMailer('test');

    $this->createTestEntity('Contact', $this->testContact);
  }

  /**
   * Create a contribution, with some defaults.
   *
   * @param array $params
   * @param int|string $key
   *   Identifier to refer to contribution by.
   *
   * @return int
   */
  public function createContribution(array $params, int|string $key): int {
    try {
      $this->ids['Contribution'][$key] = Contribution::create(FALSE)
        ->setValues(array_merge([
          'currency' => 'USD',
          'contact_id' => $this->ids['Contact']['default'],
          'receive_date' => 'now',
          'payment_instrument_id:name' => 'Credit Card',
          'financial_type_id:name' => 'Donation',
          'total_amount' => 1.23,
          'contribution_extra.original_amount' => $params['total_amount'] ?? 1.23,
          'contribution_extra.original_currency' => 'USD',
          'contribution_extra.gateway' => 'thank_you_test_gateway',
          'contribution_extra.gateway_txn_id' => 'thank_you_test_gateway 12345',
        ], $params))
        ->execute()
        ->first()['id'];
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
    return $this->ids['Contribution'][$key];
  }

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  /**
   * Get the contribution id, creating it if need be.
   *
   * @return int
   */
  public function getContributionID(): int {
    if (!isset($this->ids['Contribution'][0])) {
      $this->createContribution([], 0);
    }
    return $this->ids['Contribution'][0];
  }

  /**
   * @dataProvider booleanDataProvider
   *
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYou(bool $isUseApi): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);
    $sent = $this->sendThankYou();
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$1.23/', $sent['html']);
    $this->assertDoesNotMatchRegularExpression('/Wikimedia Endowment/', $sent['html']);

    // 2021 email has name in the subject, switching to check for the content
    $this->assertMatchesRegularExpression('/Thank you for your donation, Test/', $sent['subject']);

    // Check for tax information, DAF emails have this removed
    $this->assertMatchesRegularExpression('/tax-exempt number/', $sent['html']);
  }

  /**
   * Data provider for tests with 2 options
   *
   * @return array
   */
  public static function booleanDataProvider(): array {
    return [[FALSE], [TRUE]];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYouOrganization(): void {
    // Set up functions create an individual 0 & later tear it down. To re-use them
    // we need to punt that individual out of the 0 key.
    $this->ids['Contact'][1] = $this->ids['Contact']['default'];
    $contact_details = [
      'contact_type' => 'Organization',
      'organization_name' => 'Big Rich Bank',
      'email_primary.email' => 'money_pit@example.com',
      'email_greeting_custom' => 'Dear Friend at the Big Rich Bank',
    ];
    $this->ids['Contact'][0] = Contact::create(FALSE)->setValues($contact_details)->execute()->first()['id'];
    $sent = $this->sendThankYou($contact_details);

    $this->assertStringContainsString('Dear Friend at the Big Rich Bank', $sent['html']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYouAddCiviMailActivity(): void {
    $this->setSetting('thank_you_add_civimail_records', TRUE);
    $this->setSetting('thank_you_civimail_rate', 1.0);
    $result = $this->sendThankYou();
    $this->assertNotEmpty($result);
    $activity = $this->callAPISuccess(
      'Activity',
      'getSingle',
      [
        'contact_id' => $this->ids['Contact']['default'],
        'activity_type_id' => CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_type_id',
          'Thank you email'
        ),
      ]
    );
    $this->assertEquals(1, $this->getMailingCount());
    $sent = $this->getMailing(0);
    $this->assertEquals($activity['details'], $sent['html']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testSendEndowmentThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);
    $this->setSetting('wmf_endowment_thank_you_from_name', 'Endowment TY Sender');
    $this->createContribution(['financial_type_id:name' => 'Endowment Gift'], 0);
    $sent = $this->sendThankYou(['financial_type_id:name' => 'Endowment Gift']);
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals('Endowment TY Sender', $sent['from_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$1.23/', $sent['html']);
    $this->assertMatchesRegularExpression('/Wikimedia Endowment/', $sent['html']);

    // 2021 email has name in the subject, switching to check for the content
    $this->assertMatchesRegularExpression('/gift allows us to look far ahead.$/', $sent['subject']);
  }

  /**
   * Test that DAF (Donor Advised Fund) thank you mails do not have tax information
   *
   * @throws \CRM_Core_Exception
   */
  public function testSendDAFThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);

    // Set the gift source to Donor Advised Fund
    Contribution::update(FALSE)->addWhere('id', '=', $this->getContributionID())
      ->addValue('Gift_Data.Campaign', 'Donor Advised Fund')->execute();
    $sent = $this->sendThankYou();
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$1.23/', $sent['html']);
    // Check that tax information has been removed
    $this->assertDoesNotMatchRegularExpression('/tax-exempt number/', $sent['html']);
  }

  /**
   * Test the email is sent in the contact's preferred language when not set on the api.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYouContactLocale(): void {
    $preferred_language = 'es_MX';
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->ids['Contact']['default'])
      ->addValue('preferred_language', $preferred_language)
      ->execute();
    $mail = $this->sendThankYou([
      'preferred_language' => $preferred_language,
    ]);
    $this->assertEquals('Test - Gracias por tu donativo', $mail['subject']);
  }

  /**
   * Test how the thank yous render currency.
   *
   * Note there is some advantage in running them like this rather than a
   * dataProvider as we want to see it switching between them.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRenderThankYou(): void {
    // Note that the first string is {if $currency === 'USD'}{$currency} {/if}{$amount}
    // and the second is {if $currency === 'USD'}{$currency} {/if}{$amount} ({$currency})

    // NZD with unspecified language.
    $this->renderEnglishVariant(NULL, 'NZD', 'NZ$10.00', 'NZ$10.00 (NZD)');

    // USD with unspecified language
    $this->renderEnglishVariant(NULL, 'USD', 'USD $10.00', '$10.00 (USD)');

    // USD with specified en_US language
    $this->renderEnglishVariant('en_US', 'USD', 'USD $10.00', '$10.00 (USD)');

    // NZD with specified en_US language
    $this->renderEnglishVariant('en_US', 'NZD', 'NZ$10.00', 'NZ$10.00 (NZD)');

    // USD with specified en_NZ language
    $this->renderEnglishVariant('en_NZ', 'USD', 'USD $10.00', '$10.00 (USD)');

    // NZD with specified en_NZ language
    $this->renderEnglishVariant('en_NZ', 'NZD', 'NZ$10.00', 'NZ$10.00 (NZD)');

    // USD with specified es_MX language
    $result = $this->renderMessage('es_MX', ['currency' => 'USD']);
    $this->assertStringContainsString('USD 10.00 para apoyar', $result['html']);
    $this->assertStringContainsString(' del 2022-08-08, fue de USD 10.00 (USD).', $result['html']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRenderVenmoContainsUsername(): void {
    $result = $this->renderMessage('en_US', [
      'gateway' => 'braintree',
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'Venmo'
      ),
      'venmo_user_name' => 'venmojoe',
    ]);
    $this->assertStringContainsString('Donated with venmo username: venmojoe.', $result['html']);
  }

  /**
   * Test that contribution tags are rendered into smarty variables.@options
   *
   * ie isRecurringRestarted and isDelayed.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRenderContributionTags(): void {
    $this->createContribution([], 0);
    EntityTag::create(FALSE)->setValues([
      'entity_id' => $this->ids['Contribution'][0],
      'tag_id:name' => 'RecurringRestarted',
      'entity_table' => 'civicrm_contribution',
    ])->execute();
    $result = $this->renderMessage();
    $this->assertStringContainsString('We recently resolved a small technical issue', $result['html']);

    EntityTag::create(FALSE)->setValues([
      'entity_id' => $this->ids['Contribution'][0],
      'tag_id:name' => 'UnrecordedCharge',
      'entity_table' => 'civicrm_contribution',
    ])->execute();
    $result = $this->renderMessage();
    $this->assertStringContainsString('technical issue which caused a small number of donors', $result['html']);
  }

  /**
   * Test that Stock gift thank you mails use the stock value amount
   *
   * @throws \CRM_Core_Exception
   */
  public function testSendStockThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);

    $this->callAPISuccess('Contribution', 'create', [
      'version' => 4,
      'id' => $this->getContributionID(),
      'financial_type_id' => 'Stock',
      'Stock_Information.Stock Value' => '50.00',
      'Stock_Information.Description_of_Stock' => 'Test Stock Description',
    ]);

    $sent = $this->sendThankYou(
      ['financial_type_id:name' => 'Stock']
    );
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$50.00/', $sent['html']);
    $this->assertMatchesRegularExpression('/Test Stock Description/', $sent['html']);
  }

  /**
   * Helper function to protect test against cleanup issues.
   *
   * @param string $name
   *
   * @return int
   *
   */
  public function ensureTagExists(string $name): int {
    $tags = $this->callAPISuccess('EntityTag', 'getoptions', [
      'field' => 'tag_id',
    ]);
    if (in_array($name, $tags['values'], TRUE)) {
      return array_search($name, $tags['values'], TRUE);
    }
    $tag = $this->callAPISuccess(
      'Tag',
      'create',
      [
        'used_for' => 'civicrm_contribution',
        'name' => $name,
      ]
    );
    $this->callAPISuccess('Tag', 'getfields', ['cache_clear' => 1]);
    return (int) $tag['id'];
  }

  /**
   * Get the expected replyTo string.
   *
   * @return string
   */
  private function getExpectedReplyTo(): string {
    return "ty.{$this->ids['Contact']['default']}.{$this->ids['Contribution'][0]}" .
      '@donate.wikimedia.org';
  }

  /**
   * @param array $parameters customise parameters used in the ThankYou send function
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function sendThankYou(array $parameters = []): array {
    $mailingData = $this->getMailingData($this->getContributionID());
    $params = [
      'amount' => $mailingData['total_amount'],
      'contact_id' => $mailingData['contact_id'],
      'currency' => $mailingData['currency'],
      'first_name' => $mailingData['first_name'],
      'last_name' => $mailingData['last_name'],
      'contact_type' => $parameters['contact_type'] ?? $mailingData['contact_type'],
      'email_greeting_display' => $parameters['email_greeting_custom'] ?? $mailingData['email_greeting_display'] ?? '',
      'frequency_unit' => $mailingData['contribution_recur.frequency_unit'],
      'language' => $parameters['preferred_language'] ?? $mailingData['preferred_language'] ?? 'en_US',
      'receive_date' => $mailingData['receive_date'],
      'recipient_address' => $parameters['email_primary.email'] ?? $mailingData['email'],
      'recurring' => '1',
      'transaction_id' => "CNTCT-{$mailingData['contact_id']}",
      // shown in the body of the text
      'gift_source' => $mailingData['Gift_Data.Campaign'],
      'stock_value' => $mailingData['Stock_Information.Stock Value'],
      'stock_ticker' => $mailingData['Stock_Information.Stock Ticker'],
      'stock_qty' => $mailingData['Stock_Information.Stock Quantity'],
      'description_of_stock' => $mailingData['Stock_Information.Description_of_Stock'],
      'preferred_language' => $parameters['preferred_language'] ?? $mailingData['preferred_language'],
      'organization_name' => $parameters['organization_name'] ?? $mailingData['organization_name'] ?? '',
      'financial_type' => $parameters['financial_type_id:name'] ?? 'Donations',
    ];
    $templateName = 'thank_you';
    if ($params['financial_type'] == 'Endowment Gift') {
      $templateName = 'endowment_thank_you';
    }
    if ($params['contact_type'] == 'Organization') {
      $params['first_name'] = '';
      $params['last_name'] = '';
    }
    ThankYou::send(FALSE)
      ->setLanguage($params['language'])
      ->setContributionID($this->getContributionID())
      ->setTemplateName($templateName)
      ->setParameters($params)
      ->execute();
    $this->assertEquals(1, $this->getMailingCount());
    return $this->getMailing(0);
  }

  /**
   * @param string|null $language
   * @param array $parameters
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  protected function renderMessage(?string $language = NULL, array $parameters = []): ?array {
    $contributionID = $this->getContributionID();
    return ThankYou::render(FALSE)
      ->setLanguage($language)
      ->setContributionID($contributionID)
      ->setTemplateParameters(array_merge([
        'first_name' => 'Mickey',
        'amount' => 10,
        'last_name' => 'Mouse',
        'gift_source' => 'Random string',
        'currency' => 'NZD',
        'recurring' => FALSE,
        'transaction_id' => 123,
        'receive_date' => '2022-08-09',
        'contact_id' => $this->ids['Contact']['default'],
      ], $parameters))
      ->setTemplateName('thank_you')->execute()->first();
  }

  /**
   * @param string $html
   * @param string $firstCurrency
   * @param string $secondCurrency
   */
  protected function assertCurrencyString(string $html, string $firstCurrency, string $secondCurrency): void {
    $this->assertStringContainsString('Thank you so much for your ' . $firstCurrency . ' donation', $html);
    $this->assertStringContainsString('For your records: Your donation, number 123, on Monday, August  8, 2022 was ' . $secondCurrency . '.', $html);
  }

  /**
   * @param string|null $language
   * @param string $currency
   * @param string $firstCurrencyString
   * @param string $secondCurrencyString
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  protected function renderEnglishVariant(?string $language, string $currency, string $firstCurrencyString, string $secondCurrencyString): ?array {
    $result = $this->renderMessage($language, ['currency' => $currency]);
    $this->assertEquals('Thank you for your donation, Mickey', $result['subject']);
    $this->assertStringContainsString('Dear Mickey,', $result['html']);
    // New template has different formatting for USD vs non-USD receipts
    if ($currency === 'USD') {
      $this->assertStringContainsString(
        'Thank you so much for your ' . $firstCurrencyString . ' donation',
        $result['html']
      );
      $this->assertStringContainsString($secondCurrencyString, $result['html']);
    }
    else {
      $this->assertStringContainsString('Your donation, number 123', $result['html']);
      $this->assertCurrencyString($result['html'], $firstCurrencyString, $secondCurrencyString);
    }
    $this->assertStringNotContainsString('We recently resolved a small technical issue', $result['html']);
    return $result;
  }

  /**
   * Set a CiviCRM setting, storing the original value for tearDown.
   *
   * @param string $name
   * @param mixed $value
   */
  protected function setSetting(string $name, mixed $value): void {
    $this->originalSettings[$name] = \Civi::settings()->get($name);
    \Civi::settings()->set($name, $value);
  }

  /**
   * Basic test for sending a thank you.
   *
   * We might want to add an override parameter on the date range for the UI but for now this tests the basics.
   *
   * @throws \CRM_Core_Exception
   */
  public function testThankYouSend(): void {
    $this->setupThankyouAbleContribution();
    ThankYou::send(FALSE)
      ->setContributionID($this->ids['Contribution']['thanks'])
      ->execute();
    $contribution = $this->callAPISuccessGetSingle('Contribution', ['id' => $this->ids['Contribution']['thanks']]);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contribution['thankyou_date'])));
  }

  /**
   * Test that we are still able to force an old thank you to send.
   *
   * @throws \CRM_Core_Exception
   */
  public function testThankYouTooLate(): void {
    $this->setupThankyouAbleContribution();
    $this->callAPISuccess('Contribution', 'create', ['id' => $this->ids['Contribution']['thanks'], 'receive_date' => '2016-01-01']);
    ThankYou::send(FALSE)
      ->setContributionID($this->ids['Contribution']['thanks'])
      ->execute();
  }

  /**
   * Set up a contribution with minimum detail for a thank you.
   */
  protected function setupThankYouAbleContribution(string $key = 'first'): void {
    $wmfFields = $this->callAPISuccess('CustomField', 'get', ['custom_group_id' => 'contribution_extra'])['values'];
    $fieldMapping = [];
    foreach ($wmfFields as $field) {
      $fieldMapping[$field['name']] = $field['id'];
    }
    $this->createTestEntity('Contact', [
      'first_name' => 'bob',
      'contact_type' => 'Individual',
      'email_primary.email' => 'bob@example.com'], $key);
    $this->createContribution([
      'contact_id' => $this->ids['Contact'][$key],
      'financial_type_id' => 'Donation',
      'total_amount' => 60,
      'custom_' . $fieldMapping['total_usd'] => 60,
      'custom_' . $fieldMapping['original_amount'] => 60,
      'custom_' . $fieldMapping['original_currency'] => 'USD',
      'currency' => 'USD',
    ], 'thanks');
  }

  /**
   * Retrieve full contribution and contact record for mailing
   *
   * @param int $contributionId
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  function getMailingData(int $contributionId): array {
    $mailingData = Contribution::get(FALSE)
      ->addSelect(
        '*',
        'Gift_Data.Campaign',
        'Stock_Information.Stock Value',
        'Stock_Information.Description_of_Stock',
        'Stock_Information.Stock Ticker',
        'Stock_Information.Stock Quantity',
        'contribution_recur.frequency_unit',
        'financial_type_id:name',
      )
      ->addJoin('ContributionRecur AS contribution_recur', 'LEFT', ['contribution_recur_id', '=', 'contribution_recur.id'])
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    return array_merge(array_merge($this->testContact, [
      'contribution_id' => $mailingData['id'],
      'gateway' => 'thank_you_test_gateway',
      'no_thank_you' => '',
      'original_amount' => $mailingData['net_amount'],
      'original_currency' => $mailingData['currency'],
      'email' => $this->testContact['email_primary.email'],
      'source_type' => '',
    ]), $mailingData);
  }

}

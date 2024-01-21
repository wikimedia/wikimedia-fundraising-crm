<?php
namespace Civi\Api4;

use API_Exception;
use Civi;
use Civi\Test\Api3TestTrait;
use CRM_Core_PseudoConstant;
use PHPUnit\Framework\TestCase;
use Civi\Omnimail\MailFactory;

class ThankYouTest extends TestCase {

  use Api3TestTrait;

  protected $originalSettings = [];

  /**
   * IDS of various entities created.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
    }
    parent::setUp();
    MailFactory::singleton()->setActiveMailer('test');

    $contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'generousdonor@example.org',
      'city' => 'Somerville',
      'country' => 'US',
      'postal_code' => '02144',
      'state_province' => 'MA',
      'street_address' => '1 Davis Square',
      'first_name' => 'Test',
      'last_name' => 'Contact',
      'language' => 'en_US',
    ])['values']);
    $this->ids['Contact'] = [$contact['id']];
  }

  /**
   * Create a contribution, with some defaults.
   *
   * @param array $params
   * @param string|int $key
   *   Identifier to refer to contribution by.
   *
   * @return int
   */
  public function createContribution(array $params, $key): int {
    try {
      $this->ids['Contribution'][$key] = Contribution::create(FALSE)
        ->setValues(array_merge([
          'currency' => 'USD',
          'contact_id' => $this->ids['Contact'][0],
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
    catch (API_Exception $e) {
      $this->fail($e->getMessage());
    }
    return $this->ids['Contribution'][$key];
  }

  /**
   * Post test cleanup.
   */
  public function tearDown(): void {
    try {
      foreach ($this->originalSettings as $name => $value) {
        Civi::settings()->set($name, $value);
      }
      Contribution::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contribution'])
        ->execute();
      Contact::delete(FALSE)
        ->addWhere('id', 'IN', $this->ids['Contact'])
        ->setUseTrash(FALSE)
        ->execute();
    }
    catch (API_Exception $e) {
      $this->fail($e->getMessage());
    }
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
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);
    $sent = $this->sendThankYou();
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$1.23/', $sent['html']);
    $this->assertDoesNotMatchRegularExpression('/Wikimedia Endowment/', $sent['html']);

    // 2021 email has name in the subject, switching to check for the content
    $this->assertMatchesRegularExpression('/donation is one more reason to celebrate./', $sent['subject']);

    // Check for tax information, DAF emails have this removed
    $this->assertMatchesRegularExpression('/tax-exempt number/', $sent['html']);
  }

  /**
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function testSendThankYouOrganization(): void {
    // Set up functions create an individual 0 & later tear it down. To re-use them
    // we need to punt that individual out of the 0 key.
    $this->ids['Contact'][1] = $this->ids['Contact'][0];
    $this->ids['Contact'][0] = Contact::create(FALSE)->setValues([
      'contact_type' => 'Organization',
      'organization_name' => 'Big Rich Bank',
      'email_primary.email' => 'money_pit@example.com',
      'email_greeting_custom' => 'Dear Friend at the Big Rich Bank',
    ])->execute()->first()['id'];
    $sent = $this->sendThankYou();

    $this->assertStringContainsString('Dear Friend at the Big Rich Bank', $sent['html']);
  }

  /**
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendThankYouAddCiviMailActivity(): void {
    $this->setSetting('thank_you_add_civimail_records', TRUE);
    $this->setSetting('thank_you_civimail_rate', 1.0);
    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $activity = $this->callAPISuccess(
      'Activity',
      'getSingle',
      [
        'contact_id' => $this->ids['Contact'][0],
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
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendEndowmentThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);
    $this->setSetting('wmf_endowment_thank_you_from_name', 'Endowment TY Sender');
    $this->createContribution(['financial_type_id:name' => 'Endowment Gift'], 0);
    $sent = $this->sendThankYou();
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
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function testSendDAFThankYou(): void {
    $this->setSetting('thank_you_add_civimail_records', FALSE);

    // Set the gift source to Donor Advised Fund
    $custom_field_name = wmf_civicrm_get_custom_field_name('Gift Source');
    $this->callAPISuccess('Contribution', 'create', [
      'id' => $this->getContributionID(),
      $custom_field_name => 'Donor Advised Fund',
    ]);

    $sent = $this->sendThankYou();
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$1.23/', $sent['html']);
    // Check that tax information has been removed
    $this->assertDoesNotMatchRegularExpression('/tax-exempt number/', $sent['html']);
  }

  /**
   * Test how the thank yous render currency.
   *
   * Note there is some advantage in running them like this rather than a
   * dataProvider as we want to see it switching between them.
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
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
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

    $sent = $this->sendThankYou();
    $this->assertEquals('generousdonor@example.org', $sent['to_address']);
    $this->assertEquals('Test Contact', $sent['to_name']);
    $this->assertEquals($this->getExpectedReplyTo(), $sent['reply_to']);
    $this->assertMatchesRegularExpression('/\$50.00/', $sent['html']);
    $this->assertMatchesRegularExpression('/\Test Stock Description/', $sent['html']);

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
    return "ty.{$this->ids['Contact'][0]}.{$this->ids['Contribution'][0]}" .
      '@donate.wikimedia.org';
  }

  /**
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function sendThankYou(): array {
    $result = thank_you_for_contribution($this->getContributionID());
    $this->assertTrue($result);
    $this->assertEquals(1, $this->getMailingCount());
    return $this->getMailing(0);
  }

  /**
   * Get the number of mailings sent in the test.
   *
   * @return int
   */
  public function getMailingCount(): int {
    return MailFactory::singleton()->getMailer()->countMailings();
  }

  /**
   * Get the content on the sent mailing.
   *
   * @param int $index
   *
   * @return array
   */
  public function getMailing(int $index): array {
    return MailFactory::singleton()->getMailer()->getMailing($index);
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
    $result = ThankYou::render(FALSE)
      ->setLanguage($language)
      ->setTemplateParameters(array_merge([
        'first_name' => 'Mickey',
        'amount' => 10,
        'last_name' => 'Mouse',
        'gift_source' => 'Random string',
        'currency' => 'NZD',
        'recurring' => FALSE,
        'transaction_id' => 123,
        'receive_date' => '2022-08-09',
        'contact_id' =>  $this->ids['Contact'][0],
        'contribution_id' => $contributionID,
      ], $parameters))
      ->setTemplateName('thank_you')->execute()->first();
    return $result;
  }

  /**
   * @param string $html
   * @param string $firstCurrency
   * @param string $secondCurrency
   */
  protected function assertCurrencyString(string $html, string $firstCurrency, string $secondCurrency): void {
    $this->assertStringContainsString('one-time gift of ' . $firstCurrency . ' to support', $html);
    $this->assertStringContainsString('For your records: Your donation, number 123, on 2022-08-08 was ' . $secondCurrency . '.', $html);
  }

  /**
   * @param string|null $language
   * @param string $currency
   * @param string $firstCurrencyString
   * @param string $secondCurrencyString
   *
   * @return array|null
   */
  protected function renderEnglishVariant(?string $language, string $currency, string $firstCurrencyString, string $secondCurrencyString): ?array {
    $result = $this->renderMessage($language, ['currency' => $currency]);
    $this->assertEquals('Mickey, your  donation is one more reason to celebrate.', $result['subject']);
    $this->assertStringContainsString('Dear Mickey,', $result['html']);
    $this->assertStringContainsString('Your donation, number 123', $result['html']);
    $this->assertStringNotContainsString('We recently resolved a small technical issue', $result['html']);
    $this->assertCurrencyString($result['html'], $firstCurrencyString, $secondCurrencyString);
    return $result;
  }

  /**
   * Set a CiviCRM setting, storing the original value for tearDown.
   *
   * @param string $name
   * @param mixed $value
   */
  protected function setSetting(string $name, $value): void {
    $this->originalSettings[$name] = \Civi::settings()->get($name);
    \Civi::settings()->set($name, $value);
  }

}

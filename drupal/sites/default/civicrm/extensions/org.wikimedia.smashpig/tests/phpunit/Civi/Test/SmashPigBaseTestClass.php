<?php

namespace Civi\Test;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\Api4\Translation;
use \Civi\Api4\MessageTemplate;
use Civi\Api4\Activity;
use Civi\Test;
use Civi;
use PHPUnit\Framework\TestCase;

class SmashPigBaseTestClass extends TestCase implements HeadlessInterface {

  use Api3TestTrait;
  use EntityTrait;

  /**
   * @var string
   */
  protected $processorName = 'testSmashPig';

  /**
   * Id of processor created for the test.
   *
   * @var int
   */
  protected $processorId;

  protected $maxContactID;

  protected int $maxContributionID;

  /**
   * Stored version of failure template to restore.
   *
   * @var array
   */
  protected $originalFailureMessageTemplate;

  /**
   * Stored version of failure template to restore.
   *
   * @var array
   */
  protected $originalFailureTranslation;

  /**
   * New version, if created, of failure message template.
   *
   * @var array
   */
  protected $createdMessageTemplate;

  protected $trxn_id = 123456789;

  /**
   * Things to cleanup.
   *
   * @var array
   */
  protected $deleteThings = [
    'PaymentToken' => [],
    'PaymentProcessor' => [],
    'Contact' => [],
  ];

  /**
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Set up for test.
   *
   * @throws \CRM_Core_Exception
   */
  public function setUp(): void {
    $existing = $this->callAPISuccess(
      'PaymentProcessor', 'get', ['name' => $this->processorName, 'is_test' => 0]
    );
    if ($existing['values']) {
      $this->processorId = $existing['id'];
    }
    else {
      $processor = $this->createPaymentProcessor();
      $this->processorId = $processor['id'];
    }
    // Ensure site is set to put mail into civicrm_mailing_spool table.
    Civi::settings()->set('mailing_backend', ['outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB]);
    $this->originalFailureMessageTemplate = MessageTemplate::get(FALSE)
      ->setSelect(['*'])
      ->addWhere('workflow_name', '=', 'recurring_failed_message')
      ->execute()->first();
    $this->originalFailureTranslation = Translation::get(FALSE)
      ->setSelect(['*'])
      ->addWhere('entity_id', '=', $this->originalFailureMessageTemplate['id'])
      ->addWhere('language', '=', 'en_US')
      ->addWhere('status_id:name', '=', 'active')
      ->execute();
    $this->maxContactID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contact');
    $this->maxContributionID = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contribution');
    // Try reseting the time limit here to give us the full 180.
    // It this works we should investigate https://smaine-milianni.medium.com/set-a-max-timeout-for-your-phpunit-tests-ba160c7f53a5
    // https://www.php.net/manual/en/function.set-time-limit.php
    set_time_limit(180);
    parent::setUp();
  }

  /**
   * Post test cleanup.
   *
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    global $civicrm_setting;
    $civicrm_setting['CiviCRM Preferences']['allowPermDeleteFinancial'] = TRUE;
    if ($this->originalFailureMessageTemplate) {
      unset($this->originalFailureMessageTemplate['workflow_id']);
      MessageTemplate::update(FALSE)->setValues($this->originalFailureMessageTemplate)->execute();
    }
    foreach ($this->originalFailureTranslation as $translation) {
      Translation::update(FALSE)->setValues($translation)->execute();
    }
    if ($this->createdMessageTemplate) {
      $this->deleteThings['MessageTemplate'][] = $this->createdMessageTemplate['id'];
    }
    Contribution::delete(FALSE)->addWhere('invoice_id', 'LIKE', '%12345%')->execute();
    ContributionRecur::delete(FALSE)->addWhere('id', '=', $this->trxn_id)->execute();
    foreach ($this->deleteThings as $type => $ids) {
      foreach ($ids as $id) {
        $this->callAPISuccess($type, 'delete', ['id' => $id, 'skip_undelete' => TRUE]);
      }
    }
    // Ensure cleanup has been done!
    $this->assertEquals($this->maxContactID, $this->maxContactID = \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_contact'));
    parent::tearDown();
  }

  /**
   * CHeck that contributions that recur have the right financial type.
   *
   * This runs after the test but before tearDown starts.
   *
   * Note this test is useful for finding existing code places where this is not
   * correct. It is probably not worth porting to out extensions when we
   * discontinue this class.
   */
  protected function assertPostConditions(): void {
    $contributions = Contribution::get(FALSE)
      ->addSelect('financial_type_id')
      ->addSelect('contribution_recur_id')
      ->addWhere('contribution_recur_id', 'IS NOT EMPTY')
      ->addWhere('id', '>', $this->maxContributionID)
      ->addOrderBy('receive_date')
      ->execute();
    $recurringRecords = [];
    foreach ($contributions as $contribution) {
      if (empty($recurringRecords[$contribution['contribution_recur_id']])) {
        $this->assertEquals(RecurHelper::getFinancialTypeForFirstContribution(), $contribution['financial_type_id']);
        $recurringRecords[$contribution['contribution_recur_id']] = TRUE;
      }
      else {
        $this->assertEquals(RecurHelper::getFinancialTypeForSubsequentContributions(), $contribution['financial_type_id']);
      }
    }
  }

  /**
   * Creat test contact.
   *
   * @return array
   */
  protected function createContact(): array {
    $result = $this->callApiSuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Harry',
      'last_name' => 'Henderson',
      'preferred_language' => 'en_US',
      'legal_identifier' => '1122334455',
    ]);
    $this->callAPISuccess('Email', 'create', [
      'contact_id' => $result['id'],
      'location_type_id' => 'Home',
      'is_primary' => 1,
      'email' => 'harry@hendersons.net',
    ]);
    $this->callAPISuccess('Address', 'create', [
      'contact_id' => $result['id'],
      'location_type_id' => 'Billing',
      'is_primary' => 1,
      'country_id' => 'US',
    ]);
    $this->deleteThings['Contact'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  /**
   * @param array $contributionRecur
   * @param array $overrides
   *
   * @return array
   */
  protected function createContribution(array $contributionRecur, array $overrides = []): array {
    $params = $overrides + [
        'contact_id' => $contributionRecur['contact_id'],
        'currency' => 'USD',
        'total_amount' => 12.34,
        'contribution_recur_id' => $contributionRecur['id'],
        'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
        'trxn_id' => $contributionRecur['trxn_id'],
        'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
        'invoice_id' => $contributionRecur['invoice_id'] . '|recur-' . 123456,
      ];
    return $this->createTestEntity('Contribution', $params);
  }

  /**
   * Create a payment processor instance.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function createPaymentProcessor(): array {
    $typeRecord = $this->callAPISuccess(
      'PaymentProcessorType', 'getSingle', ['name' => 'smashpig_ingenico']
    );
    $accountType = key(\CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL,
      " AND v.name = 'Asset' "));
    $query = "
        SELECT id
        FROM   civicrm_financial_account
        WHERE  is_default = 1
        AND    financial_account_type_id = {$accountType}
      ";
    $financialAccountId = \CRM_Core_DAO::singleValueQuery($query);
    $params = [];
    $params['payment_processor_type_id'] = $typeRecord['id'];
    $params['name'] = $this->processorName;
    $params['domain_id'] = \CRM_Core_Config::domainID();
    $params['is_active'] = TRUE;
    $params['financial_account_id'] = $financialAccountId;
    $result = $this->callAPISuccess('PaymentProcessor', 'create', $params);
    $this->deleteThings['PaymentProcessor'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  /**
   * Create a payment token.
   *
   * @param int $contactId
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function createToken(int $contactId): array {
    $result = $this->callAPISuccess('PaymentToken', 'create', [
      'contact_id' => $contactId,
      'payment_processor_id' => $this->processorId,
      'token' => 'abc123-456zyx-test12',
      'ip_address' => '12.34.56.78',
    ]);
    $this->deleteThings['PaymentToken'][] = $result['id'];
    return $result['values'][$result['id']];
  }

  /**
   * Create recurring contributionn.
   *
   * @param array $token
   * @param array $overrides
   *
   * @return array
   */
  protected function createContributionRecur(array $token, array $overrides = []): array {
    $trxn_id = ($overrides['trxn_id'] ?? NULL) ?: $this->trxn_id;
    $invoice_id = 678000 . '.' . $trxn_id;
    $params = $overrides + [
        'contact_id' => $token['contact_id'],
        'amount' => 12.34,
        'currency' => 'USD',
        'frequency_unit' => 'month',
        'frequency_interval' => 1,
        'installments' => 0,
        'failure_count' => 0,
        'start_date' => gmdate('Y-m-d H:i:s', strtotime('-1 month')),
        'create_date' => gmdate('Y-m-d H:i:s', strtotime('-1 month')),
        'payment_token_id' => $token['id'],
        'cancel_date' => NULL,
        'cycle_day' => gmdate('d', strtotime('-12 hours')),
        'payment_processor_id' => $this->processorId,
        'next_sched_contribution_date' => gmdate('Y-m-d H:i:s', strtotime('-12 hours')),
        'trxn_id' => 'RECURRING INGENICO ' . $trxn_id,
        'processor_id' => $trxn_id,
        'invoice_id' => $invoice_id,
        'contribution_status_id:name' => 'Pending',
        'contribution_recur_smashpig.processor_contact_id' => $invoice_id,
        'contribution_recur_smashpig.rescue_reference' => NULL,
      ];
    return $this->createTestEntity('ContributionRecur', $params);
  }

  /**
   * Set up a recurring contribution with relevant entities.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function setupRecurring(): array {
    $contact = $this->createContact();
    $token = $this->createToken((int) $contact['id']);
    $contributionRecur = $this->createContributionRecur($token);
    $this->createContribution($contributionRecur);
    return $contributionRecur;
  }

  /**
   * Set up the message template for failures.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function setupFailureTemplate(): void {
    $msgHtml = 'Dear {contact.first_name},
      We cancelled your recur of {contribution_recur.currency} {contribution_recur.amount}
      and we are sending you this at {contact.email}
      this month of {now.MMMM}
      {contribution_recur.amount}';
    $subject = 'Hey {contact.first_name}';

    if ($this->originalFailureMessageTemplate) {
      MessageTemplate::update(FALSE)
        ->setCheckPermissions(FALSE)
        ->setValues(['id' => $this->originalFailureMessageTemplate['id'], 'msg_text' => $msgHtml, 'msg_subject' => $subject])
        ->execute();
      foreach ($this->originalFailureTranslation as $translatedValue) {
        if ($translatedValue['entity_field'] === 'msg_html' || $translatedValue['entity_field'] === 'msg_text') {
          Translation::update(FALSE)
            ->setValues(['id' => $translatedValue['id'], 'string' => $msgHtml])->execute();
        }
        if ($translatedValue['entity_field'] === 'msg_subject') {
          Translation::update(FALSE)
            ->setValues(['id' => $translatedValue['id'], 'string' => $subject])->execute();
        }
      }
    }
    else {
      $this->createdMessageTemplate = MessageTemplate::create()
        ->setCheckPermissions(FALSE)
        ->setValues(['workflow_name' => 'recurring_failed_message', 'msg_subject' => $subject, 'msg_text' => $msgHtml])->execute()->first();
    }
  }

  /**
   * Gdt the latest activity for a failure email.
   *
   * @param int $contributionRecurID
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getLatestFailureMailActivity(int $contributionRecurID) {
    $activity = Activity::get()->setCheckPermissions(FALSE)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('subject', 'LIKE', 'Recur fail message : %')
      ->addWhere('source_record_id', '=', $contributionRecurID)
      ->addOrderBy('activity_date_time', 'DESC')
      ->execute()->first();
    if ($activity) {
      $this->deleteThings['Activity'][] = $activity['id'];
    }
    return $activity;
  }

}

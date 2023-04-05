<?php

use Civi\Api4\Translation;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use Civi\Test\Api3TestTrait;
use \Civi\Api4\MessageTemplate;
use Civi\Api4\Activity;

class SmashPigBaseTestClass extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use Api3TestTrait;

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

  /**
   * Things to cleanup.
   *
   * @var array
   */
  protected $deleteThings = [
    'Contribution' => [],
    'ContributionRecur' => [],
    'PaymentToken' => [],
    'PaymentProcessor' => [],
    'Contact' => [],
  ];

  /**
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Set up for test.
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function setUp(): void {
    civicrm_initialize();
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
    Civi::settings()->set('mailing_backend', ['outBound_option' => CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB]);
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
    parent::setUp();
  }

  /**
   * Post test cleanup.
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
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
    foreach ($this->deleteThings as $type => $ids) {
      foreach ($ids as $id) {
        $this->callAPISuccess($type, 'delete', ['id' => $id, 'skip_undelete' => TRUE]);
      }
    }
    parent::tearDown();
  }

  /**
   * Creat test contact.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function createContact():array {
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
   *
   * @throws \CRM_Core_Exception
   */
  protected function createContribution(array $contributionRecur, array $overrides = []): array {
    $params = $overrides + [
        'contact_id' => $contributionRecur['contact_id'],
        'currency' => 'USD',
        'total_amount' => 12.34,
        'contribution_recur_id' => $contributionRecur['id'],
        'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
        'trxn_id' => $contributionRecur['trxn_id'],
        'financial_type_id' => 1,
        'invoice_id' => mt_rand(10000, 10000000) . '.' . mt_rand(1, 20) . '|recur-' . mt_rand(100000, 100000000),
        'skipRecentView' => 1,
      ];
    $result = $this->callAPISuccess('Contribution', 'create', $params);
    $this->deleteThings['Contribution'][] = $result['id'];
    return $result['values'][$result['id']];
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
    $accountType = key(CRM_Core_PseudoConstant::accountOptionValues('financial_account_type', NULL,
      " AND v.name = 'Asset' "));
    $query = "
        SELECT id
        FROM   civicrm_financial_account
        WHERE  is_default = 1
        AND    financial_account_type_id = {$accountType}
      ";
    $financialAccountId = CRM_Core_DAO::singleValueQuery($query);
    $params = [];
    $params['payment_processor_type_id'] = $typeRecord['id'];
    $params['name'] = $this->processorName;
    $params['domain_id'] = CRM_Core_Config::domainID();
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
  protected function createToken(int $contactId):array {
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
   *
   * @throws \CRM_Core_Exception
   */
  protected function createContributionRecur(array $token, array $overrides = []):array {
    $processor_id = mt_rand(10000, 100000000);
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
        'trxn_id' => 'RECURRING INGENICO ' . $processor_id,
        'processor_id' => $processor_id,
        'invoice_id' => mt_rand(10000, 10000000) . '.' . mt_rand(1, 20),
        'contribution_status_id:name' => 'Pending',
      ];
    $result = Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first();
    $this->deleteThings['ContributionRecur'][] = $result['id'];
    return $result;
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
   * @throws \API_Exception
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
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getLatestFailureMailActivity(int $contributionRecurID) {
    $activity = Activity::get()->setCheckPermissions(FALSE)
      ->addWhere('activity_type_id:name', '=', 'Email')
      ->addWhere('subject', 'LIKE', 'Recur fail message : %')
      ->addWhere('source_record_id', '=', $contributionRecurID)
      ->addOrderBy('activity_date_time', 'DESC')
      ->execute()->first();
    if($activity) {
      $this->deleteThings['Activity'][] = $activity['id'];
    }
    return $activity;
  }

}

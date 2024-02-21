<?php

namespace Civi\WMFQueue;

use Civi\Api4\Contact;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;

class BaseQueueTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  use Test\EntityTrait;

  /**
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    // Since we can't kill jobs on jenkins this prevents a loop from going
    // on for too long....
    set_time_limit(180);

    // Initialize SmashPig with a fake context object
    $config = TestingGlobalConfiguration::create();
    TestingContext::init($config);
  }

  /**
   * Create an contact of type Individual.
   *
   * @param array $params
   * @param string $identifier
   *
   * @return int
   */
  public function createIndividual(array $params = [], string $identifier = 'danger_mouse'): int {
    return $this->createTestEntity('Contact', array_merge([
      'first_name' => 'Danger',
      'last_name' => 'Mouse',
      'contact_type' => 'Individual',
    ], $params), $identifier)['id'];
  }

  /**
   * Helper to make getting the contact ID even shorter.
   *
   * @param string $identifier
   *
   * @return int
   */
  protected function getContactID(string $identifier = 'danger_mouse'): int {
    return $this->ids['Contact'][$identifier];
  }

  /**
   * @param string $identifier
   *
   * @return array|null
   */
  protected function getContact(string $identifier = 'danger_mouse'): ?array {
    try {
      return Contact::get(FALSE)->addWhere('id', '=', $this->ids['Contact'][$identifier])
        ->addSelect('is_opt_out')
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Load the message from the json file.
   *
   * @param string $name
   *
   * @return mixed
   * @noinspection PhpMultipleClassDeclarationsInspection
   */
  public function loadMessage(string $name) {
    try {
      return json_decode(file_get_contents(__DIR__ . '/data/' . $name . '.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->fail('could not load json:' . $name . ' ' . $e->getMessage());
    }
  }

}

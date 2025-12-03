<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

class DedupeBaseTestClass extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

  protected $ids = [];

  protected $settings = [];

  /**
   * Set up for headless tests.
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    foreach ($this->ids as $entity => $ids) {
      if ($entity === 'Contact') {
        Contribution::delete(FALSE)
          ->addWhere('contact_id', 'IN', $this->ids['Contact'])
          ->execute();
        Contact::delete(FALSE)
          ->addWhere('id', 'IN', $this->ids['Contact'])
          ->setUseTrash(FALSE)
          ->execute();
      }
    }
    foreach ($this->settings as $key => $value) {
      \Civi::settings()->set($key, $value);
    }
    if (!empty($this->ids['CustomGroup'])) {
      CustomField::delete(FALSE)->addWhere('custom_group_id', 'IN', $this->ids['CustomGroup'])->execute();
      CustomGroup::delete(FALSE)->addWhere('id', 'IN', $this->ids['CustomGroup'])->execute();
    }
    parent::tearDown();
  }

  public function setSetting($key, $value) {
    $this->settings[$key] = \Civi::settings()->get($key);
    \Civi::settings()->set($key, $value);
  }

}

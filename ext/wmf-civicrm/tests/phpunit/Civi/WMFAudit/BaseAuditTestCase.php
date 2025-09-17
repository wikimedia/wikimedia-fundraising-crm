<?php

namespace Civi\WMFAudit;

use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;
use Civi\WMFEnvironmentTrait;
use Civi\WMFQueueTrait;
use Civi\Test\EntityTrait;
use SmashPig\Core\ConfigurationException;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use PHPUnit\Framework\TestCase;

class BaseAuditTestCase extends TestCase {
  use WMFEnvironmentTrait;
  use WMFQueueTrait;
  use EntityTrait;

  public function setUp(): void {
    // This sets the working log directory to the CiviCRM upload directory.
    // It is found under sites/default/files/civicrm/upload & is web-writable,
    // outside of git, and somewhat durable.
    \Civi::settings()->set('wmf_audit_directory_working_log', \CRM_Core_Config::singleton()->uploadDir);
    \Civi::settings()->set('wmf_audit_directory_payments_log', __DIR__ . '/data/logs/');
    $this->setUpWMFEnvironment();
    parent::setUp();
  }

  /**
   * Create a temporary directory and return the name
   *
   * @return string|boolean directory path if creation was successful, or false
   */
  protected function getTempDir() {
    $tempFile = tempnam(sys_get_temp_dir(), 'wmfDrupalTest_');
    if (file_exists($tempFile)) {
      unlink($tempFile);
    }
    if (!mkdir($tempFile) && !is_dir($tempFile)) {
      throw new \RuntimeException(sprintf('Directory "%s" was not created', $tempFile));
    }
    if (is_dir($tempFile)) {
      return $tempFile . '/';
    }
    return FALSE;
  }

  protected function assertMessages(array $expectedMessages): void {
    try {
      foreach ($expectedMessages as $queueName => $expected) {
        $actual = [];
        $queue = QueueWrapper::getQueue($queueName);
        while (TRUE) {
          $message = $queue->pop();
          if ($message === NULL) {
            break;
          }
          SourceFields::removeFromMessage($message);
          $actual[] = $message;
        }
        // As we often declare the expected in a dataProvider they may not be
        // known during test set up so only check if they are in the expected array.
        foreach ($expected as $index => $message) {
          if (!array_key_exists('contribution_id', $message)) {
            unset($actual[$index]['contribution_id']);
            unset($actual[$index]['parent_contribution_id']);
          }
        }
        $this->assertEquals($expected, $actual);
      }
    }
    catch (ConfigurationException $e) {
      $this->fail('SmashPig configuration problem :' . $e->getMessage());
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function runAuditor(): Result {
    return WMFAudit::parse()
      ->setGateway($this->gateway)
      ->setIsMoveCompletedFile(FALSE)
      ->execute();
  }

}

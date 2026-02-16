<?php

namespace Civi\WMFAudit;

use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;
use Civi\WMFEnvironmentTrait;
use Civi\WMFQueueTrait;
use Civi\Test\EntityTrait;
use League\Csv\Exception;
use League\Csv\Reader;
use SmashPig\Core\ConfigurationException;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;
use PHPUnit\Framework\TestCase;

class BaseAuditTestCase extends TestCase {
  use WMFEnvironmentTrait;
  use WMFQueueTrait;
  use EntityTrait;

  protected string $auditFileBaseDirectory = '';

  protected string $gateway;

  public function setUp(): void {
    $this->setUpWMFEnvironment();
    // This sets the working log directory to the CiviCRM upload directory.
    // It is found under sites/default/files/civicrm/upload & is web-writable,
    // outside of git, and somewhat durable.
    $this->setSetting('wmf_audit_directory_working_log', \CRM_Core_Config::singleton()->uploadDir);
    $this->setSetting('wmf_audit_directory_payments_log', __DIR__ . '/data/logs/');
    $this->auditFileBaseDirectory = __DIR__ . '/data/' . ucfirst($this->gateway);
    parent::setUp();
  }

  public function tearDown(): void {
    $this->tearDownWMFEnvironment();
    parent::tearDown();
  }

  protected function setAuditDirectory(string $subDir): void {
    $directory = $this->auditFileBaseDirectory . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR;
    $this->setSetting('wmf_audit_directory_audit', $directory);
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @return Reader
   */
  public function getRows(string $directory, string $fileName): array {
    $this->setAuditDirectory($directory);
    // First let's have a process to create some TransactionLog entries.
    $file = $this->auditFileBaseDirectory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $this->gateway . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR . $fileName;
    try {
      $csv = Reader::from($file, 'r');
      $csv->setHeaderOffset(0);
    } catch (Exception $e) {
      $this->fail('Failed to read csv' . $file . ': ' . $e->getMessage());
    }
    $rows = [];
    foreach ($csv as $row) {
      $rows[] = $row;
    }
    return $rows;
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
          unset($actual[$index]['contribution_tracking'], $actual[$index]['transaction_details']);
          if (!array_key_exists('contribution_id', $message)) {
            unset($actual[$index]['contribution_id']);
            unset($actual[$index]['parent_contribution_id']);
          }
          if (array_key_exists('backend_processor', $actual[$index])) {
            $expected[$index] += array_fill_keys([
              'backend_processor',
              'backend_processor_txn_id',
            ], NULL);
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
   * Run the audit process.
   */
  protected function runAuditor($fileName = NULL): Result {
    try {
      return WMFAudit::parse()
        ->setGateway($this->gateway)
        ->setFile((string) $fileName)
        ->setSettleMode('queue')
        ->setIsMoveCompletedFile(FALSE)
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @return array
   */
  public function prepareForAuditProcessing(string $directory, string $fileName): array {
    $rows = $this->getRows($directory, $fileName);
    foreach ($rows as $row) {
      $this->createTransactionLog($row);
    }
    return $row;
  }

  /**
   * @param string $directory
   * @param string $fileName
   * @param string $batchName
   * @return array
   */
  public function runAuditBatch(string $directory, string $fileName, string $batchName = ''): array {
    $this->prepareForAuditProcessing($directory, $fileName);

    $auditResult['batch'] = $this->runAuditor($fileName);
    $this->processDonationsQueue();
    $this->processContributionTrackingQueue();
    $this->processRefundQueue();
    $this->processSettleQueue();

    $this->processContributionTrackingQueue();
    if ($batchName) {
      $auditResult['validate'] = WMFAudit::generateBatch(FALSE)
        ->setBatchPrefix($batchName)
        ->setIsOutputCsv(TRUE)
        ->setEmailSummaryAddress('test@example.org')
        ->execute();

      foreach ($auditResult['validate'] as $row) {
        $this->assertEquals(0, array_sum($row['validation']), print_r(array_filter($row), TRUE));
        foreach ($row['csv'] ?? [] as $path) {
          $rows = Reader::from($path)->setHeaderOffset(0)->getRecords();
          foreach ($rows as $line) {
            $this->assertGreaterThanOrEqual(0, $line['DEBIT']);
            $this->assertGreaterThanOrEqual(0, $line['CREDIT']);
          }
        }
      }
    }
    return (array) $auditResult;
  }

}

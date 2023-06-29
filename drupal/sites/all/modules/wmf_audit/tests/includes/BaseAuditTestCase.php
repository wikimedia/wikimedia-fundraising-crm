<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\Messages\SourceFields;

class BaseAuditTestCase extends BaseWmfDrupalPhpUnitTestCase {

  public function setUp(): void {
    // Reset any runtime options that may have been set by previous tests
    wmf_audit_runtime_options([]);
    parent::setUp();
  }

  protected function assertMessages($expectedMessages) {
    foreach ($expectedMessages as $queueName => $expected) {
      $actual = [];
      $queue = QueueWrapper::getQueue($queueName);
      while (TRUE) {
        $message = $queue->pop();
        if ($message === null) {
          break;
        }
        SourceFields::removeFromMessage($message);
        $actual[] = $message;
      }
      $this->assertEquals($expected, $actual);
    }
  }
}

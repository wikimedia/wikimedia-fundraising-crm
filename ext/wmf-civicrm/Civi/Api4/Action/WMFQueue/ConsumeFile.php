<?php

namespace Civi\Api4\Action\WMFQueue;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFException\WMFException;
use Civi\WMFQueue\QueueConsumer;

/**
 * @method string getFileName()
 * @method $this setFileName(string $fileName)
 * @method string getLimit()
 * @method $this setLimit(string $fileName)
 * @method string getQueueConsumer()
 * @method $this setQueueConsumer(string $queueConsumer)
 */
class ConsumeFile extends AbstractAction {

  /**
   * @var string
   */
  protected string $fileName = '';

  /**
   * Queue consumer name.
   *
   * e.g if the class is \Civi\WMFQueue\RefundQueueConsumer
   * then this would be 'Refund' as the rest is assumed.
   *
   * @var string
   */
  protected string $queueConsumer = '';

  /**
   * Limit number of messages, or 0 for unlimited.
   *
   * Useful when processing a large file of dummy data.
   *
   * @var int
   */
  protected int $limit = 0;

  /**
   * This is a stand-in string for smash-pig but in theory we might call a different consumer?
   * @var string
   */
  protected $queueName = 'donations';

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    \Civi::log('wmf')->info('Executing: {queue} on {file_name}', ['file_name' => $this->getFileName(), 'queue' => $this->queueConsumer]);
    \CRM_SmashPig_ContextWrapper::createContext($this->queueName);
    $contents = file_get_contents($this->fileName);
    $messages = json_decode($contents, TRUE);
    if (!is_array($messages)) {
      throw new \CRM_Core_Exception("Error decoding JSON in $this->fileName");
    }
    if (!isset($messages[0]) || !is_array($messages[0])) {
      $messages = [$messages];
    }

    $consumer = $this->loadQueueConsumer();
    $consumer->initiateStatistics();
    \Civi::log('wmf')->info('WMFQueue: Processing input file {path} and feeding to ' . $this->queueConsumer . 'Consumer',
      ['path' => realpath($this->fileName)]);

    $processed = 0;
    foreach ($messages as $message) {
      try {
        $consumer->processMessage( $message );
        $processed++;
        if ( $this->limit && $processed === $this->limit ) {
          break;
        }
      } catch (WMFException $ex) {
        \Civi::log('wmf')->info('WMF Exception: ' . $ex->getMessage());
      }
    }

    $consumer->reportStatistics($processed);
    if ($processed > 0) {
      \Civi::log('wmf')->info('Successfully processed {processed} from queue {queue_name}', ['processed' => $processed, 'queue_name' => $this->getQueueName()]);
    }
    else {
      \Civi::log('wmf')->info('No {queue_name} items processed.', ['queue_name' => $this->getQueueName()]);
    }

    $result[] = ['dequeued' => $processed];
  }

  /**
   * @return QueueConsumer
   */
  private function loadQueueConsumer(): QueueConsumer {
    $class = '\Civi\WMFQueue\\' . $this->getQueueConsumer() . 'QueueConsumer';
    return new $class($this->queueName);
  }

}

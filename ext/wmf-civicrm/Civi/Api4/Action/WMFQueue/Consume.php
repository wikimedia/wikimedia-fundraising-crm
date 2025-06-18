<?php

namespace Civi\Api4\Action\WMFQueue;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFQueue\QueueConsumer;
use CRM_SmashPig_ContextWrapper;

/**
 * @method string getQueueName()
 * @method $this setQueueName(string $queueName)
 * @method string getQueueConsumer()
 * @method $this setQueueConsumer(string $queueConsumer)
 * @method $this setMessageLimit(int $messageLimit) Set consumer batch limit
 * @method int getMessageLimit() Get consumer batch limit
 * @method $this setTimeLimit(int $timeLimit) Set consumer time limit (seconds)
 * @method int getTimeLimit() Get consumer time limit (seconds)
 * @method $this setWaitForMessages(bool $waitForMessages) Sets whether to wait for new messages until time expires
 * @method bool getWaitForMessages() Gets whether to wait for new messages until time expires (defaults to FALSE)
 */
class Consume extends AbstractAction {

  /**
   * @var int
   */
  protected $messageLimit = 0;

  /**
   * @var int
   */
  protected $timeLimit = 0;

  /**
   * @var string
   */
  protected $queueName;

  /**
   * @var bool
   */
  protected $waitForMessages = FALSE;

  /**
   * Queue consumer name.
   *
   * e.g if the class is \Civi\WMFQueue\RefundQueueConsumer
   * then this would be 'Refund' as the rest is assumed.
   *
   * @var string
   */
  protected $queueConsumer;

  public function _run(Result $result) {
    // @todo -this feels wrong - maybe we fire
    // a listener? what does it do?
    CRM_SmashPig_ContextWrapper::createContext($this->getQueueName());
    Civi::log('wmf')->info('Executing: {queue_consumer}', ['queue_consumer' => $this->getQueueName()]);

    $consumer = $this->loadQueueConsumer();
    $consumer->initiateStatistics();

    $processed = $consumer->dequeueMessages();

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
    return new $class(
      $this->getQueueName(),
      $this->getTimeLimit(),
      $this->getMessageLimit(),
      $this->getWaitForMessages()
    );
  }

}

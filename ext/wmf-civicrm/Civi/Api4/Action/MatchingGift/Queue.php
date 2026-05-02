<?php
namespace Civi\Api4\Action\MatchingGift;

use Civi\Api4\Action\WMFQueue\Queue as BaseQueue;

/**
 * Queue the message.
 *
 * Action allows for queuing in process control, coworker or immediate handling
 * (or it will in time).
 *
 * @method $this setQueueMethod(string $queueMethod)
 */
class Queue extends BaseQueue {
  /**
   * Queue name.
   *
   * The name of the queue to use - this implicitly tells us how to handle if using
   * the api more directly as the queue-name will map to the api entity.
   *
   * @required
   *
   * @var string
   */
  protected string $queueName = 'MatchingGift';
}

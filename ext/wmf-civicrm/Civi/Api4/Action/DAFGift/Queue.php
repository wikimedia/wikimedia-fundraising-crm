<?php
namespace Civi\Api4\Action\DAFGift;

use Civi\Api4\Action\WMFQueue\Queue as BaseQueue;

/**
 *  Queue the message.
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
  protected string $queueName = 'DAFGift';

}

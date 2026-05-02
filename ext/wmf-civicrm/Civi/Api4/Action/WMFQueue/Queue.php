<?php
namespace Civi\Api4\Action\WMFQueue;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\DAFGift;
use Civi\Api4\MatchingGift;

/**
 * Class to queue messages.
 *
 * Not currently called directly but as a parent.
 *
 * @method $this setQueueMethod(string $queueMethod)
 * @method $this setMessage(array $message)
 * @method $this setQueueName(string $queueName)
 */
class Queue extends AbstractAction {

  /**
   * Queue method.
   *
   * One of coworker, immediate or process-control.
   *
   * @required
   *
   * @var string
   */
  protected string $queueMethod = 'coworker';

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
  protected string $queueName;

  /**
   * The message to queue.
   *
   * If queuing to the api this will be the 'entity'
   *
   * @required
   *
   * @var array
   */
  protected array $message;

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function _run(Result $result): void {
    // @todo - this will get more generic in time - but for now it's mostly a place holder to
    // either run the MatchingGift or DAFGift create, next will add push it to coworker.
    if ($this->queueMethod !== 'immediate') {
      throw new \CRM_Core_Exception('queueMethod not implemented' . $this->queueMethod);
    }
    if ($this->queueName === 'MatchingGift') {
      $result = MatchingGift::save($this->checkPermissions)
        ->addRecord($this->message)
        ->execute();
      return;
    }
    if ($this->queueName === 'DAFGift') {
      $result = DAFGift::save($this->checkPermissions)
        ->addRecord($this->message)
        ->execute();
      return;
    }
  }

}

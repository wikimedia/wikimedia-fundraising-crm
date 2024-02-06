<?php

namespace Civi\Api4\Action\UpiDonationsQueue;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFQueue\UpiDonationsQueueConsumer;
use CRM_SmashPig_ContextWrapper;

/**
 * @method $this setBatch(int $batch) Set consumer batch limit
 * @method int getBatch() Get consumer batch limit
 * @method $this setTimeLimit(int $timeLimit) Set consumer time limit (seconds)
 * @method int getTimeLimit() Get consumer time limit (seconds)
 */
class Consume extends AbstractAction {

  /**
   * @var int
   */
  protected $batch = 0;

  /**
   * @var int
   */
  protected $timeLimit = 0;

  public function _run(Result $result) {
    CRM_SmashPig_ContextWrapper::createContext('civicrm');
    Civi::log('wmf')->info('Executing: UpiDonationsQueue.consume');

    $consumer = new UpiDonationsQueueConsumer(
      'upi-donations',
      $this->getTimeLimit(),
      $this->getBatch()
    );
    $dequeued = $consumer->dequeueMessages();
    $result['dequeued'] = $dequeued;
  }

}

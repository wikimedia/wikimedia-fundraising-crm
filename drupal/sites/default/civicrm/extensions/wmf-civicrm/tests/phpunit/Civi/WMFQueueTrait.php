<?php

namespace Civi;

use Civi\Api4\WMFQueue;

trait WMFQueueTrait {

  /**
   * Process anything in the contribution tracking queue.
   */
  public function processContributionTrackingQueue(): void {
    $this->processQueue('contribution-tracking', 'ContributionTracking');
  }

  /**
   * Process the given queue.
   *
   * @param string $queueName
   * @param string $queueConsumer
   *
   * @return array|null
   */
  public function processQueue(string $queueName, string $queueConsumer): ?array {
    try {
      return WMFQueue::consume()
        ->setQueueName($queueName)
        ->setQueueConsumer($queueConsumer)
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to process ' . $queueName . ' with consumer ' . $queueConsumer . "\n" . $e->getMessage());
    }
  }

  /**
   * Temporarily set foreign exchange rates to known values.
   */
  protected function setExchangeRates(int $timestamp, array $rates): void {
    foreach ($rates as $currency => $rate) {
      exchange_rate_cache_set($currency, $timestamp, $rate);
    }
  }

}

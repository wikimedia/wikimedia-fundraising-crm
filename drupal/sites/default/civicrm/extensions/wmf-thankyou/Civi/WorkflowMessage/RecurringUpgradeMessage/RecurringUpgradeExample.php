<?php

namespace Civi\WorkflowMessage\RecurringUpgradeMessage;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\RecurringUpgradeMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class RecurringUpgradeExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/recurring_upgrade_message/upgrade',
      'workflow' => 'recurring_upgrade_message',
      'title' => ts('Recurring Upgrade'),
      'tags' => ['preview'],
    ];
  }

  public function build( array &$example ): void {
    $message = new RecurringUpgradeMessage();
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $message->setContributionRecur($this->getContributionRecur());
    $example['data'] = $this->toArray($message);
  }

  /**
   * @return array[]
   */
  protected function getContributionRecur(): array {
    return [
      'id' => 0,
      'amount' => '12.30',
      'frequency_unit' => 'month',
      'currency' => 'USD',
    ];
  }
}

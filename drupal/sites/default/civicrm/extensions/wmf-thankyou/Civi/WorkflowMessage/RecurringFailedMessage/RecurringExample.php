<?php

namespace Civi\WorkflowMessage\RecurringFailedMessage;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\RecurringFailedMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class RecurringExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/recurring_failed_message/failed',
      'workflow' => 'recurring_failed_message',
      'title' => ts('Recurring Failure'),
      'tags' => ['preview'],
    ];
  }

  /**
   * @param array $example
   *
   * @throws \CRM_Core_Exception
   */
  public function build(array &$example): void {
    $message = new RecurringFailedMessage();
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
      'frequency_unit' => 'monthly',
      'currency' => 'USD',
    ];
  }

}

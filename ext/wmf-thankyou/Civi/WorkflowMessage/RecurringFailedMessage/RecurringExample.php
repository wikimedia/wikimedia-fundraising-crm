<?php

namespace Civi\WorkflowMessage\RecurringFailedMessage;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\RecurringFailedMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class RecurringExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/recurring_failed_message/month',
      'workflow' => 'recurring_failed_message',
      'title' => ts('Recurring Failure - monthly'),
      'tags' => ['preview'],
    ];
    yield [
      'name' => 'workflow/recurring_failed_message/year',
      'workflow' => 'recurring_failed_message',
      'title' => ts('Recurring Failure - annual'),
      'tags' => ['preview'],
    ];
  }

  /**
   * @param array $example
   *
   * @throws \CRM_Core_Exception
   */
  public function build(array &$example): void {
    $contributionRecur = $this->getContributionRecur($example['name']);
    $message = new RecurringFailedMessage();
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $message->setContributionRecur($contributionRecur);
    $example['data'] = $this->toArray($message);
  }

  /**
   * @return array[]
   */
  protected function getContributionRecur(string $name): array {
    $parts = explode('/', $name);
    return [
      'id' => 0,
      'amount' => '12.30',
      'frequency_unit' => $parts[2],
      'next_sched_contribution_date' => '2026-01-01',
      'currency' => 'USD',
    ];
  }

}

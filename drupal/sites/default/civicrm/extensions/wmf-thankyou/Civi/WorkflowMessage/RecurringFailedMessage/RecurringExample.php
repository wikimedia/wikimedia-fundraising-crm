<?php

namespace Civi\WorkflowMessage\RecurringFailedMessage;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\RecurringFailedMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class RecurringExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => implode('/', [
        'workflow',
        $this->getWorkflowName(),
        $this->getExampleName()
      ]),
      'title' => ts('Recurring Failure'),
      'tags' => ['preview'],
    ];
  }

  /**
   * Get the name of the workflow this is used in.
   *
   * (wrapper for confusing property name)
   *
   * @return string
   */
  protected function getWorkflowName(): string {
    return $this->wfName;
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
      'currency' => 'USD',
    ];
  }

}

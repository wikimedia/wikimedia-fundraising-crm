<?php

namespace Civi\WorkflowMessage\EOYThankYou;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\EOYThankYou;
use Civi\WorkflowMessage\WorkflowMessageExample;

class EOYThankYouExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => implode('/', [
        'workflow',
        $this->getWorkflowName(),
        $this->getExampleName(),
      ]),
      'title' => ts('End of year'),
      'tags' => ['preview'],
    ];
    yield [
      'name' => implode('/', [
        'workflow',
        $this->getWorkflowName(),
        'custom_range',
      ]),
      'start_date' => '2023-04-09 12:34:78',
      'end_date' => '2023-04-09 12:34:78',
      'title' => ts('Custom Range'),
      'tags' => ['preview'],
    ];
    yield [
      'name' => implode('/', [
        'workflow',
        $this->getWorkflowName(),
        'from_date',
      ]),
      'start_date' => '2023-04-09 12:34:78',
      'title' => ts('From specific date (note it adds today as the end here)'),
      'tags' => ['preview'],
    ];
    yield [
      'name' => implode('/', [
        'workflow',
        $this->getWorkflowName(),
        'before_date',
      ]),
      'end_date' => '2023-04-09 12:34:78',
      'title' => ts('Before specific date'),
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
    $message = new EOYThankYou();
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $message->setStartDateTime($example['start_date'] ?? '');
    $message->setEndDateTime($example['end_date'] ?? '');
    if (empty($example['end_date']) && empty($example['start_date'])) {
      $message->setYear(date('Y') - 1);
    }
    $message->setActiveRecurring(TRUE);
    $message->setCancelledRecurring(TRUE);
    $message->setContributions($this->getContributions());
    $example['data'] = $this->toArray($message);
  }

  /**
   * @return array[]
   */
  protected function getContributions(): array {
    $contributions = [
      ['receive_date' => (date('Y') - 1) . '-02-02', 'total_amount' => 50],
      ['receive_date' => (date('Y') - 1) . '-03-03', 'total_amount' => 800.10, 'contribution_extra.original_currency' => 'CAD'],
      ['receive_date' => (date('Y') - 1) . '-05-04', 'total_amount' => 20.00],
      ['receive_date' => (date('Y') - 1) . '-10-21', 'total_amount' => 50.00, 'contribution_extra.original_currency' => 'CAD'],
      ['receive_date' => (date('Y') - 1) . '-05-04', 'total_amount' => 20.00, 'financial_type_id:name' => 'Endowment Gift'],
    ];
    foreach ($contributions as $index => $contribution) {
      $contributions[$index] = array_merge([
        'financial_type_id:name' => 'Donation',
        'currency' => 'USD',
        'contribution_extra.original_amount' => $contribution['total_amount'],
      ], $contribution);
    }
    return $contributions;
  }

}

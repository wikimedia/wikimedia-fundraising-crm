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
        $this->getExampleName()
      ]),
      'title' => ts('End of year'),
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
    $message->setYear(date('Y') - 1);
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

<?php

namespace Civi\WorkflowMessage\DoubleOptIn;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\DoubleOptInMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class DoubleOptInExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => implode('/', [
        'workflow',
        'double_opt_in',
        $this->getExampleName(),
      ]),
      'title' => ts('Double Opt In Message'),
      'tags' => ['preview'],
      'workflow' => 'double_opt_in',
    ];
  }

  public function build(array &$example): void {
    $message = new DoubleOptInMessage();
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $this->setWorkflowName('double_opt_in');
    $example['data'] = $this->toArray($message);
  }

}

<?php

namespace Civi\WorkflowMessage\DoubleOptInLeadGen;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\DoubleOptInLeadGenMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class DoubleOptInLeadGenExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => implode('/', [
        'workflow',
        'double_opt_in_lead_gen',
        $this->getExampleName(),
      ]),
      'title' => ts('Lead Gen Double Opt-In'),
      'tags' => ['preview'],
      'workflow' => 'double_opt_in_lead_gen',
    ];
  }

  public function build(array &$example): void {
    $message = new DoubleOptInLeadGenMessage();
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $this->setWorkflowName('double_opt_in_lead_gen');
    $example['data'] = $this->toArray($message);
  }

}

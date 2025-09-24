<?php

namespace Civi\WorkflowMessage\SetPrimaryEmail;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\SetPrimaryEmailMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class SetPrimaryEmailExample extends WorkflowMessageExample {

public function getExamples(): iterable {
  yield [
      'name' => implode('/', [
      'workflow',
      'set_primary_email',
      $this->getExampleName(),
    ]),
    'title' => ts('Set Primary Email'),
    'tags' => ['preview'],
    'workflow' => 'set_primary_email',
    ];
  }

  public function build(array &$example): void {
    $message = new SetPrimaryEmailMessage();
    $message->setUrl('https://donorpreferences.wikimedia.org/index.php?title=Special:EmailPreferences/confirmEmail&email=aaaaa%40example.com&contact_id=123456&checksum=1234abcdfcc5350734872650c8969e89_1732312064_168');
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $message->setDate('2024-01-01');
    $message->setTime('12:00');
    $message->setOldEmail('old@email.com');
    $message->setOldEmail('old@email.com');
    $message->setNewEmail('new@email.com');
    $this->setWorkflowName('set_primary_email');
    $example['data'] = $this->toArray($message);
  }
}

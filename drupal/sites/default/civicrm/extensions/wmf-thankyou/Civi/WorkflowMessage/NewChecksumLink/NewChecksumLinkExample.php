<?php

namespace Civi\WorkflowMessage\NewChecksumLink;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\NewChecksumLinkMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class NewChecksumLinkExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => implode('/', [
        'workflow',
        'new_checksum_link',
        $this->getExampleName(),
      ]),
      'title' => ts('New Checksum Link'),
      'tags' => ['preview'],
      'workflow' => 'new_checksum_link',
    ];
  }

  public function build(array &$example): void {
    $message = new NewChecksumLinkMessage();
    $message->setUrl('https://donorpreferences.wikimedia.org/index.php?title=Special:EmailPreferences/emailPreferences&contact_id=123456&checksum=1234abcdfcc5350734872650c8969e89_1732312064_168');
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $this->setWorkflowName('new_checksum_link');
    $example['data'] = $this->toArray($message);
  }
}

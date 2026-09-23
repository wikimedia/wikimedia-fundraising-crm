<?php

namespace Civi\WorkflowMessage\NewChecksumLink;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\NewChecksumLinkMessage;
use Civi\WorkflowMessage\WorkflowMessageExample;

class NewChecksumLinkExample extends WorkflowMessageExample {

  private const PREFERENCES_URL = 'https://donorpreferences.wikimedia.org/index.php?title=Special:EmailPreferences/emailPreferences&contact_id=123456&checksum=1234abcdfcc5350734872650c8969e89_1732312064_168';

  private const DONOR_PORTAL_URL = 'https://donorpreferences.wikimedia.org/index.php?title=Special:DonorPortal&contact_id=123456&checksum=1234abcdfcc5350734872650c8969e89_1732312064_168';

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/new_checksum_link/' . $this->getExampleName(),
      'title' => ts('New Checksum Link'),
      'tags' => ['preview'],
      'workflow' => 'new_checksum_link',
    ];
    yield [
      'name' => 'workflow/new_checksum_link/secondary_email',
      'title' => ts('New Checksum Link (requested on a secondary email)'),
      'tags' => ['preview'],
      'workflow' => 'new_checksum_link',
    ];
    yield [
      'name' => 'workflow/new_checksum_link/account_not_found',
      'title' => ts('New Checksum Link (no account found)'),
      'tags' => ['preview'],
      'workflow' => 'new_checksum_link',
    ];
  }

  public function build(array &$example): void {
    $message = new NewChecksumLinkMessage();
    switch (basename($example['name'])) {
      case 'secondary_email':
        // Asked for the donor portal with an address that isn't their primary one.
        $message->setContact(DemoData::example('entity/Contact/Alex'));
        $message->setTargetPage('DonorPortal');
        $message->setUrl(self::DONOR_PORTAL_URL);
        $message->setIsSecondaryEmail(TRUE);
        $message->setPreferencesUrl(self::PREFERENCES_URL);
        break;

      case 'account_not_found':
        // Nothing matched the address, so there is no contact and no link.
        $message->setTargetPage('DonorPortal');
        $message->setAccountFound(FALSE);
        $message->setRetryUrl('https://donorpreferences.wikimedia.org/index.php?title=Special:DonorPortal');
        break;

      default:
        $message->setContact(DemoData::example('entity/Contact/Alex'));
        $message->setTargetPage('EmailPreferences');
        $message->setUrl(self::PREFERENCES_URL);
        break;
    }
    $this->setWorkflowName('new_checksum_link');
    $example['data'] = $this->toArray($message);
  }

}

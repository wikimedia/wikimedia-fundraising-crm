<?php

namespace Civi\Api4\WMFContact;

use Civi\Api4\Contact;
use Civi\Api4\GroupContact;
use Civi\Api4\WMFContact;
use Civi\WMFEnvironmentTrait;
use Civi\Test\EntityTrait;
use PHPUnit\Framework\TestCase;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 * @group epcV4
 **/
class BackfillOptInTest extends TestCase {
  use WMFEnvironmentTrait;
  use EntityTrait;

  public function testBackFill(): void {
    $this->createIndividual(['Communication.opt_in' => TRUE, 'email_primary.email' => 'opted_in@example.org'], 'opted_in');
    WMFContact::backfillOptIn(FALSE)
      ->setDate(strtotime('a week ago'))
      ->setEmail('opted_in@example.org')
      ->setOpt_in(FALSE)->execute();
    $contact = Contact::get(FALSE)
      ->addSelect('Communication.opt_in')
      ->addWhere('email_primary.email', '=', 'opted_in@example.org')
      ->execute()->first();
    $this->assertEquals(TRUE, $contact['Communication.opt_in']);

    WMFContact::backfillOptIn(FALSE)
      ->setDate(strtotime('+ 1 week'))
      ->setEmail('opted_in@example.org')
      ->setOpt_in(FALSE)->execute();
    $contact = Contact::get(FALSE)
      ->addSelect('Communication.opt_in')
      ->addWhere('email_primary.email', '=', 'opted_in@example.org')
      ->execute()->first();
    $this->assertEquals(TRUE, $contact['Communication.opt_in']);
    $group = GroupContact::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addSelect('group_id:name')
      ->execute()->first();
    $this->assertEquals('opt_out_backfill', $group['group_id:name']);
  }
}

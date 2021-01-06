<?php

namespace Civi\Api;

require_once __DIR__ . '/../../../../../../../../sites/all/modules/wmf_eoy_receipt/EoySummary.php';

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Email;
use PHPUnit\Framework\TestCase;
use Civi\Api4\EOYEmail;

class EoySummaryTest extends TestCase {

  /**
   * Test that Japanese characters in a name are rendered correctly.
   *
   * We no longer use the Japanese template as the name is not
   * in it due to https://phabricator.wikimedia.org/T271189
   *
   * @throws \API_Exception
   */
  public function testRenderEmailInJapanese(): void {
    $contactID = Contact::create()
      ->setCheckPermissions(FALSE)
      // Suzuki is a common Japanese name - the last name here is Suzuki in kanji.
      ->setValues(['last_name' => 'Suzuki', 'first_name' => '鈴木', 'preferred_language' => 'ca_ES', 'contact_type' => 'Individual'])
      ->addChain('add_email',
        Email::create()
          ->setValues([
            'email' => 'suzuki@example.com',
          ])
          ->addValue('contact_id', '$id'))
      ->addChain('add_a_donation',
          Contribution::create()
            ->setValues([
              'total_amount' => 5,
              'currency' => 'JPY',
              'receive_date' => '2020-08-06',
              'financial_type_id:name' => 'Donation',
            ])
            ->addValue('contact_id', '$id')
       )->execute()->first()['id'];

    $message = EOYEmail::render()->setCheckPermissions(FALSE)
      ->setYear(2020)->setContactID($contactID)->execute()->first();
    $this->assertContains('Hola 鈴木', $message['html']);
  }

}

<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;
use Civi\WMFMailTracking\CiviMailStore;

/**
 * Base class for tests of CiviMail helper classes
 */
class CiviMailTestBase extends BaseWmfDrupalPhpUnitTestCase {

	protected $source = 'wmf_communication_test';
	protected $body = '<p>Dear Wikipedia supporter,</p><p>You are beautiful.</p>';
	protected $subject = 'Thank you';
	/**
	 * @var CiviMailStore
	 */
	protected $mailStore;
	protected $contactID;
	protected $emailID;

	public function setUp(): void {
		parent::setUp();
		civicrm_initialize();
		$this->mailStore = new CiviMailStore();
		$contact = $this->getContact( 'generaltrius@hondo.mil', 'Trius', 'Hondo' );
		$this->emailID = $contact[ 'emailID' ];
		$this->contactID = $contact[ 'contactID' ];
	}

	protected function getContact( $email, $firstName, $lastName ) {
		$emailResult = civicrm_api3( 'Email', 'get', array(
			'email' => $email,
		) );
		$firstResult = reset( $emailResult['values'] );
		if ( $firstResult ) {
			return array(
				'emailID' => $firstResult['id'],
				'contactID' => $firstResult['contact_id'],
			);
		} else {
			$contactResult = civicrm_api3( 'Contact', 'create', array(
				'first_name' => $firstName,
				'last_name' => $lastName,
				'contact_type' => 'Individual',
			) );
			$emailResult = civicrm_api3( 'Email', 'create', array(
				'email' => $email,
				'contact_id' => $contactResult['id'],
			) );
			return array(
				'emailID' => $emailResult['id'],
				'contactID' => $contactResult['id'],
			);
		}
	}

	public function tearDown(): void {
		$this->callAPISuccess( 'Email', 'delete', ['id' => $this->emailID]);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->contactID, 'skip_undelete' => TRUE]);
		parent::tearDown();
	}
}

<?php

use SmashPig\Core\Context;

class BaseWmfDrupalPhpUnitTestCase extends PHPUnit_Framework_TestCase {
    public function setUp() {
        parent::setUp();

        if ( !defined( 'DRUPAL_ROOT' ) ) {
            throw new Exception( "Define DRUPAL_ROOT somewhere before running unit tests." );
        }

        global $user, $_exchange_rate_cache;
        $_exchange_rate_cache = array();

        $user = new stdClass();
        $user->name = "foo_who";
        $user->uid = "321";
        $user->roles = array( DRUPAL_AUTHENTICATED_RID => 'authenticated user' );
    }

    public function tearDown() {
		Context::set(); // Nullify any SmashPig context for the next run
		parent::tearDown();
	}

	/**
	 * Temporarily set foreign exchange rates to known values
	 *
	 * TODO: Should reset after each test.
	 */
	protected function setExchangeRates( $timestamp, $rates ) {
		foreach ( $rates as $currency => $rate ) {
			exchange_rate_cache_set( $currency, $timestamp, $rate );
		}
	}

	/**
	 * Create a temporary directory and return the name
	 * @return string|boolean directory path if creation was successful, or false
	 */
	protected function getTempDir() {
		$tempFile = tempnam( sys_get_temp_dir(), 'wmfDrupalTest_' );
		if ( file_exists( $tempFile ) ) {
			unlink( $tempFile );
		}
		mkdir( $tempFile );
		if ( is_dir( $tempFile ) ) {
			return $tempFile . '/';
		}
		return false;
	}

    /**
     * API wrapper function from core (more or less).
     *
     * so we can ensure they succeed & throw exceptions without littering the test with checks.
     *
     * This is not the full function but it we think it'w worth keeping a copy it should maybe
     * go in the parent.
     *
     * @param string $entity
     * @param string $action
     * @param array $params
     * @param mixed $checkAgainst
     *   Optional value to check result against, implemented for getvalue,.
     *   getcount, getsingle. Note that for getvalue the type is checked rather than the value
     *   for getsingle the array is compared against an array passed in - the id is not compared (for
     *   better or worse )
     *
     * @return array|int
     */
    public function callAPISuccess($entity, $action, $params, $checkAgainst = NULL) {
        $params = array_merge(array(
            'version' => 3,
            'debug' => 1,
        ),
            $params
        );
        try {
            $result = civicrm_api3($entity, $action, $params);
        }
        catch (CiviCRM_API3_Exception $e) {
            $this->assertEquals(0, $e->getMessage() . print_r($e->getExtraParams(), TRUE));
        }
        $this->assertAPISuccess($result, "Failure in api call for $entity $action");
        return $result;
    }

    /**
     * Check that api returned 'is_error' => 0.
     *
     * @param array $apiResult
     *   Api result.
     * @param string $prefix
     *   Extra test to add to message.
     */
    public function assertAPISuccess($apiResult, $prefix = '') {
        if (!empty($prefix)) {
            $prefix .= ': ';
        }
        $errorMessage = empty($apiResult['error_message']) ? '' : " " . $apiResult['error_message'];

        if (!empty($apiResult['debug_information'])) {
            $errorMessage .= "\n " . print_r($apiResult['debug_information'], TRUE);
        }
        if (!empty($apiResult['trace'])) {
            $errorMessage .= "\n" . print_r($apiResult['trace'], TRUE);
        }
        $this->assertEquals(0, $apiResult['is_error'], $prefix . $errorMessage);
    }


  /**
   * Emulate a logged in user since certain functions use that.
   * value to store a record in the DB (like activity)
   * CRM-8180
   *
   * @return int
   *   Contact ID of the created user.
   */
  public function imitateAdminUser() {
    $result = $this->callAPISuccess('UFMatch', 'get', array(
      'uf_id' => 1,
      'sequential' => 1,
    ));
    if (empty($result['id'])) {
      $contact = $this->callAPISuccess('Contact', 'create', array(
        'first_name' => 'Super',
        'last_name' => 'Duper',
        'contact_type' => 'Individual',
        'api.UFMatch.create' => array('uf_id' => 1, 'uf_name' => 'Wizard'),
      ));
      $contactID = $contact['id'];
    }
    else {
      $contactID = $result['values'][0]['contact_id'];
    }
    $session = CRM_Core_Session::singleton();
    $session->set('userID', $contactID);
    CRM_Core_Config::singleton()->userPermissionClass = new CRM_Core_Permission_UnitTests();
    CRM_Core_Config::singleton()->userPermissionClass->permissions = array('Edit All Contacts', 'Access CiviCRM', 'Administer CiviCRM');
    return $contactID;
  }

}

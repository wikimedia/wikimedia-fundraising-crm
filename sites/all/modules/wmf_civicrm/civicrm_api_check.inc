<?php

class WMFCiviAPICheck{

  /**
   * Checks the result of a call to civicrm_api to ensure that the call
   * returned a result in the expected format as well as without error
   *
   * @static
   * @param array $result the API result to check
   * @return bool
   */
  static function check_api_result($result) {

    if (!is_array($result)){
      return FALSE;
    }

    if (array_key_exists('is_error', $result)) {
      if ($result['is_error'] == "0") {
        return TRUE;
      }
      else {
        // error encountered in API
        return FALSE;
      }
    }

    // malformed API response
    return FALSE;
  }

  /**
   * Simplifies a CiviCRM API result by "flattening" the result into an array
   * containing the desired record at the top level
   *
   * @static
   * @param array $result the API result to simplify
   * @param integer $id the id of the desired record
   * @param boolean $multiple_okay FALSE if only one record should hav been returned.
   * 	true if we multiple results are okay and we should just pick one.
   * @return mixed the simplified array or false if the simplification failed
   * @throws Exception
   */
  static function check_api_simplify($result, $id=NULL, $multiple_okay=FALSE) {
    if (!self::check_api_result($result, TRUE)) {
      // invalid API response
      return FALSE;
    }
    if (array_key_exists('values', $result)) {
      if ($id != NULL) {
        // look for the expected id
        if (array_key_exists($id, $result['values'])) {
          return $result['values'][$id];
        }
        else {
          // the expected contact was not found in the result
          return FALSE;
        }
      }
      else {
		if(array_key_exists("count", $result) && $result['count'] == 1){
			if(array_key_exists('id', $result)){
				if( array_key_exists( $result['id'], $result['values'] ) ){
					return $result['values'][strval($result['id'])];
				}
				// @todo There is a bug in the CiviCRM Contribution API where the results
				// array is not keyed properly when only a single result is returned
				if( array_key_exists( 'contribution_id', $result['values'][0] ) ){
					return $result['values'][0];
				}
			}
		}
	    if(array_key_exists("count", $result) && $result['count'] > 1){
			if(!$multiple_okay){
				throw new Exception("Multiple results found. Cannot simplify.");
			}
			// i'm feeling special, return the last element
			end($result['values']);
			return $result['values'][key($result['values'])];
		}
      }
    }
    // malformed contact result
    return FALSE;

  }

  /**
   * Checks a CiviCRM API Contact result for errors and ensures that any "required" fields
   * are present in the resultant array.  Optionally, the result can be checked for the
   * presence of a specific contact_id to ensure that the correct record was returned.
   *
   * @static
   * @param array $result the API result to check
   * @param integer $contact_id the id of the desired record
   * @return bool true if the array is a valid contact result, false otherwise
   */
  static function check_api_contact($result, $contact_id = NULL) {

    // TODO: it would probably be good to define a list of WMF "assumed" fields
    // that we this function checks to ensure are in the resultant contact

    if (!self::check_api_result($result)) {
      return FALSE;
    }

    if (array_key_exists('values', $result)) {
      if ($contact_id != NULL) {
        if (array_key_exists($contact_id, $result['values'])) {
          return TRUE;
        }
        else {
          // the expected contact was not found in the result
          return FALSE;
        }
      }
      // a valid contact record was returned
      return TRUE;
    }
    // malformed contact result
    return FALSE;
  }

  /**
   * Checks a CiviCRM API Contribution result for errors and ensures that any
   * "required" fields are present in the resultant array.  Optionally, the result
   * can be checked for the presence of a specific contribution_id to ensure that the
   * correct record was returned.
   *
   * @static
   * @param array $result the API result to check
   * @param integer $contribution_id the id of the desired record
   * @return bool true if the array is a valid contribution result, false otherwise
   */
  static function check_api_contribution($result, $contribution_id = NULL) {

    // TODO: it would probably be good to define a list of WMF "assumed" fields
    // that we this function checks to ensure are in the resultant contact

    if (!self::check_api_result($result)) {
      return FALSE;
    }

    if (array_key_exists('values', $result)) {
      if ($contribution_id != NULL) {
        if (array_key_exists($contribution_id, $result['values'])) {
          return TRUE;
        }
        else {
          // the expected contribution was not found in the result
          return FALSE;
        }
      }
      // a valid contribution record was returned
      return TRUE;
    }
    // malformed contribution result
    return FALSE;
  }
}

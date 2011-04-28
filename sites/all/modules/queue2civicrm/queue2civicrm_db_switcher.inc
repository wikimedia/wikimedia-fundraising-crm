<?php 
/**
 * Class to facilitate swithing of db access between CiviCRM and Drupal 
 */

class queue2civicrm_db_switcher {
  
  public function __construct() {
    global $db_url;
      
    /**
     * Dynamically configure the CiviCRM database
     * 
     * Borrowed from old civicrm_api module
     */
    if (!is_array($db_url) || !array_key_exists('civicrm', $db_url)) {    
      require_once conf_path() . '/civicrm.settings.php';
    
      $dsn = str_replace( '?new_link=true', '', CIVICRM_DSN );
      $dsn = str_replace( 'mysql:', 'mysqli:', CIVICRM_DSN );
      
      if (is_array($db_url)) {
        $db_url[ 'civicrm' ] = $dsn;
      }
      else {
        $db_url = array(
          'default' => $db_url,
          'civicrm' => $dsn,
        );
      }
    }
  }

  public function use_civicrm() {
    return db_set_active( 'civicrm' );
  }

  public function use_default() {
    return db_set_active();
  }
  
  public function __destruct() {
    $this->use_default();
  }
}
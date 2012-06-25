<?php 

# TODO if we're in control of all db calls during "switched" contexts, we can use a better db library, such as PDO

/**
 * Manage the database switching object
 */
function wmf_civicrm_get_dbs() {
  static $dbs = NULL;
  if ( !$dbs ) {
    $dbs = new db_switcher();
  }
  return $dbs;
}


/**
 * Class to facilitate switching of db access between CiviCRM and Drupal 
 */
class db_switcher {

  /**
   * Holds name of currently active db
   * @var string
   */
  protected $cur_db = 'default';
  
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
      # TODO: can't instantiate mysql and mysqli drivers in the same instance, so I've commented out the following line.  If we're relying on the improved driver's cleanup or something, let's enforce that CIVICRM_DSN is already connected through mysqli.
      #$dsn = str_replace( 'mysql:', 'mysqli:', CIVICRM_DSN );
      
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
    $this->set_cur_db( 'civicrm' );
    return db_set_active( 'civicrm' );
  }

  public function use_default() {
    $this->set_cur_db( 'default' );
    return db_set_active();
  }
  
  /**
   * Set the name of the currently active db.
   * 
   * @param string $cur_db
   * @throws Exception
   */
  protected function set_cur_db( $cur_db ) {
    global $db_url;
    
    if ( !isset( $db_url[ $cur_db ] )) {
      throw new Exception( $cur_db . ' is not supported by queue2civicrm_db_switcher.' );
    }
    
    $this->cur_db = $cur_db;
  }
  
  /**
   * Fetch the name of the currently active db
   * @return string
   */
  public function get_cur_db() {
    return $this->cur_db;
  }  
  
  public function __destruct() {
    $this->use_default();
  }
}

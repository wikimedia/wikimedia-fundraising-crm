<?php 

# TODO if we're in control of all db calls during "switched" contexts, we can use a better db library, such as PDO

/**
 * Manage the database switching object
 */
function wmf_civicrm_get_dbs() {
    return new db_switcher();
}


/**
 * Class to facilitate switching of db access between CiviCRM, Drupal,
 * and other dbs.
 */
class db_switcher {

  /**
   * Holds name of currently active db
   * @var string
   */
  static protected $cur_db;
  protected $db_stack = array();

  public function __construct() {
    global $db_url;

    /**
     * Dynamically configure the CiviCRM database url
     */
    if ( !is_array( $db_url ) ) {
        $db_url = array(
            'default' => $db_url,
        );
    }

    if ( !array_key_exists( 'civicrm', $db_url ) ) {
        require_once conf_path() . '/civicrm.settings.php';
        $db_url[ 'civicrm' ] = str_replace( '?new_link=true', '', CIVICRM_DSN );
    }
  }

  /**
   * Set the currently active db and push the previous db to a stack.
   * 
   * @param string $cur_db
   * @throws Exception
   */
  public function push( $db_name ) {
      $previous_db = $this->set_db( $db_name );

      $this->db_stack[] = $previous_db;
  }

  /**
   * Restore the previously active db connection.
   */
  public function pop() {
      if ( !$this->db_stack ) {
          throw new Exception( "No db context to pop!" );
      }

      $this->set_db( array_pop( $this->db_stack ) );
  }

  /**
   * Set the current db by name.  Do some sanity checking.
   *
   * @return previous db name
   */
  protected function set_db( $db_name ) {
      if ( $db_name === self::$cur_db ) {
          return $db_name;
      }

      global $db_url;
      if ( !array_key_exists( $db_name, $db_url ) ) {
          throw new Exception( $db_name . ' is not defined in global $db_url.' );
      }

      $previous_db = db_set_active( $db_name );

      if ( self::$cur_db && $previous_db !== self::$cur_db ) {
          throw new Exception( "DB switcher got very confused!" );
      }
      self::$cur_db = $db_name;

      return $previous_db;
  }

  public function __destruct() {
      if ( $this->db_stack ) {
          // this time, grab the *original* db connection
          $this->set_db( array_shift( $this->db_stack ) );
      }
  }
}

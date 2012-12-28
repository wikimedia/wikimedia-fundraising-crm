<?php 

/**
 * Get a grip on multiple database connections.
 *
 * The db_switcher maintains a stack, beginning with the db connection
 * set previous to your first call to ->push().  You can call push()
 * as many times as needed, and pop() is only necessary if you want to
 * exlicitly return to the last context, without knowing what that was.
 * The argument to push() is a database connection name as specified
 * in settings.php :: $db_url.  If you haven't specified the 'civicrm'
 * there, it is automatically determined by loading civicrm.settings.php.
 *
 * Remember that most Drupal functions require the 'default' db
 * connection and will die horribly if another connection is active.
 *
 * db_switcher's destructor has amazing side-effects: any mess you've
 * made is cleaned up, and the db connection is returned to the state
 * it was in when you began calling ->push().
 *
 * It's perfectly safe to nest db_switcher contexts, but never safe
 * to have more than one instance of a switcher in the same function
 * scope.
 *
 * @return db_switcher
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
   * Holds name of currently active db you have set.  Before push() is
   * called, we don't care and don't know what db connection is active.
   *
   * @var string
   */
  static protected $cur_db;

  /**
   * Previous db connections, beginning with the context before we
   * changed anything.
   */
  protected $db_stack = array();

  /**
   * Make sure that global $db_url is in the modern array( name -> url )
   * form we're expecting, modify it if not.  Add the 'civicrm' url if
   * necessary.
   */
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
   * Not necessary to pop(), that will happen by default when this object
   * is destructed.
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
   * @throws Exception
   *   when the stack is empty
   */
  public function pop() {
      if ( !$this->db_stack ) {
          throw new Exception( "No db context to pop!" );
      }

      $this->set_db( array_pop( $this->db_stack ) );
  }

  /**
   * Set the current db by name.  Does some sanity checking:
   * - The database connection name exists in $db_url
   * - Nobody messed with the active connection behind our back
   *
   * @return string
   *   previous db name
   * @throws Exception
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

  /**
   * Magically pop everything off the stack.  We take a shortcut here and
   * jump directly to the connection set previous to our first call. If
   * no push() has happened, do nothing.
   */
  public function __destruct() {
      if ( $this->db_stack ) {
          // this time, grab the *original* db connection
          $this->set_db( array_shift( $this->db_stack ) );
      }
  }

  /**
   * Utility to make a db prefix string of the form "SCHEMA.TABLEPREFIX"
   */
  function get_prefix($name) {
      global $db_url, $db_prefix;

      $prefix = "";

      $url = $db_url[$name];
      if (preg_match( "/^(?:[^:]+):\/\/(?:[^:]+):(?:[^@]+)@(?:[^\/]+)\/(?P<schema>.+)$/", $url, $matches )) {
          $prefix = $matches['schema'] . ".";
      }
      if ( $name === 'default' ) {
          $prefix .= $db_prefix;
      }
      return $prefix;
  }
}

<?php

/**
 * A programmatic interface for gmetric
 */
class GangliaReporter {

  /**
   * Path to gmetric executable
   * @var string
   */
  protected $gmetric_path = null;

  /**
   * Constructor
   *
   * Initializes by setting gmetric path.
   * @param $gmetric_path
   */
  function __construct( $gmetric_path=null ) {
    // if the gmetric path hasn't been explicitly set, try to auto-locate it.
    if ( !$gmetric_path ) {
      $gmetric_path = self::locateGmetricPath();
    }
    $this->setGmetricPath( $gmetric_path );
  }

  /**
   * Get gmetric path
   *
   * In the event that gmetric path is not set in scope, attempt to auto-locate
   * and set it.
   *
   * @return string
   */
  function getGmetricPath() {
    if ( !$this->gmetric_path ) {
      $this->setGmetricPath( self::locateGmetricPath() );
    }
    return $this->gmetric_path;
  }

  /**
   * Set gmetric path
   *
   * Throws an exception in the event that the path to the gmetric binary does
   * not exist.
   *
   * @param $path
   */
  function setGmetricPath( $path ) {
    if ( !file_exists( $path )) {
      throw new Exception( 'Gmetric path "' . $path . '" does not exist.' );
    }
    $this->gmetric_path = $path;
  }

  /**
   * Executes gmetric call
   *
   * Sends arbitrary data to Ganglia using gmetric.
   *
   * Params the same as arguments for gmetric.  Run gmetric --help for more
   * info.
   *
   * @param $name
   * @param $value
   * @param $type
   * @param $units
   * @param $slope
   * @param $tmax
   * @param $dmax
   * @return bool
   */
  function sendMetric( $name, $value, $type = 'int8', $units = '', $slope = 'both', $tmax = 60, $dmax = 0 ) {
    // pack the params and their values into an array so we can more easily build our string of options
    $opts = array(
      'name' => $name,
      'value' => $value,
      'type' => $type,
      'units' => $units,
      'slope' => $slope,
      'tmax' => $tmax,
      'dmax' => $dmax,
    );

    // construct our command to execute, along with our string of options
    $cmd = escapeshellcmd( $this->getGmetricPath() . ' ' . $this->prepareOptions( $opts ));
    // execute the gmetric command...
    exec( $cmd, $output, $retval );

    // return true/false depending on success.
    if( $retval === 0 ) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Returns a formatted string of options to be passed to executable
   *
   * Takes an associative array of $option_name => $option_value.
   *
   * @param $opts
   * @return string
   */
  function prepareOptions( array $opts ) {
    foreach( $opts as $opt => $val ) {
      $opts[ $opt ] = "--" . $opt . "=" . escapeshellarg( $val );
    }
    return implode( " ", $opts );
  }

  /**
   * Attempt to auto-determine location of gmetric
   * @return string Gmetric path (or null)
   */
  static function locateGmetricPath() {
    $path = exec( 'which gmetric' );
    if ( !strlen( $path )) {
      return null;
    }
    return $path;
  }
}
<?php

/**
 * A programmatic interface for gmetric
 */
class GangliaReporter {

  /**
   * Path to gmetric executable
   * @var string
   */
  protected $gmetric_path = null;

  /**
   * Constructor
   *
   * Initializes by setting gmetric path.
   * @param $gmetric_path
   */
  function __construct( $gmetric_path=null ) {
    // if the gmetric path hasn't been explicitly set, try to auto-locate it.
    if ( !$gmetric_path ) {
      $gmetric_path = self::locateGmetricPath();
    }
    $this->setGmetricPath( $gmetric_path );
  }

  /**
   * Get gmetric path
   *
   * In the event that gmetric path is not set in scope, attempt to auto-locate
   * and set it.
   *
   * @return string
   */
  function getGmetricPath() {
    if ( !$this->gmetric_path ) {
      $this->setGmetricPath( self::locateGmetricPath() );
    }
    return $this->gmetric_path;
  }

  /**
   * Set gmetric path
   *
   * Throws an exception in the event that the path to the gmetric binary does
   * not exist.
   *
   * @param $path
   */
  function setGmetricPath( $path ) {
    if ( !file_exists( $path )) {
      throw new Exception( 'Gmetric path "' . $path . '" does not exist.' );
    }
    $this->gmetric_path = $path;
  }

  /**
   * Executes gmetric call
   *
   * Sends arbitrary data to Ganglia using gmetric.
   *
   * Params the same as arguments for gmetric.  Run gmetric --help for more
   * info.
   *
   * @param $name
   * @param $value
   * @param $type
   * @param $units
   * @param $slope
   * @param $tmax
   * @param $dmax
   * @return bool
   */
  function sendMetric( $name, $value, $type = 'int8', $units = '', $slope = 'both', $tmax = 60, $dmax = 0 ) {
    // pack the params and their values into an array so we can more easily build our string of options
    $opts = array(
      'name' => $name,
      'value' => $value,
      'type' => $type,
      'units' => $units,
      'slope' => $slope,
      'tmax' => $tmax,
      'dmax' => $dmax,
    );

    // construct our command to execute, along with our string of options
    $cmd = escapeshellcmd( $this->getGmetricPath() . ' ' . $this->prepareOptions( $opts ));
    // execute the gmetric command...
    exec( $cmd, $output, $retval );

    // return true/false depending on success.
    if( $retval === 0 ) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Returns a formatted string of options to be passed to executable
   *
   * Takes an associative array of $option_name => $option_value.
   *
   * @param $opts
   * @return string
   */
  function prepareOptions( array $opts ) {
    foreach( $opts as $opt => $val ) {
      $opts[ $opt ] = "--" . $opt . "=" . escapeshellarg( $val );
    }
    return implode( " ", $opts );
  }

  /**
   * Attempt to auto-determine location of gmetric
   * @return string Gmetric path (or null)
   */
  static function locateGmetricPath() {
    $path = exec( 'which gmetric' );
    if ( !strlen( $path )) {
      return null;
    }
    return $path;
  }
}

<?php


namespace Civi\WMFException;

class FredgeDataValidationException extends WMFException {
  public function __construct( $message ) {
    // FIXME: other exception types are descriptive of the error,
    // not just its source module.  Make like the others.
    parent::__construct( parent::FREDGE, $message );
  }
}

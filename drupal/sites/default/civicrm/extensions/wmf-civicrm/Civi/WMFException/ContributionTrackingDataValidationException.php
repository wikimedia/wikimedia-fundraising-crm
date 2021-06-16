<?php

namespace Civi\WMFException;

use \WmfException;

class ContributionTrackingDataValidationException extends WmfException {

  public function __construct($message) {
    parent::__construct(parent::CONTRIBUTION_TRACKING, $message);
  }
}

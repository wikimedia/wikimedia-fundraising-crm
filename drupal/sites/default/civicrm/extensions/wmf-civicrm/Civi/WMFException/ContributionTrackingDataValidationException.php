<?php

namespace Civi\WMFException;

class ContributionTrackingDataValidationException extends WMFException {

  public function __construct($message) {
    parent::__construct(parent::CONTRIBUTION_TRACKING, $message);
  }
}

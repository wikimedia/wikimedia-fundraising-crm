<?php

use CRM_Wmf_ExtensionUtil as E;

class CRM_Wmf_Page_Segment extends CRM_Core_Page {

  public function run() {
    $calculatedData = new \Civi\WMFHook\CalculatedData();
    $segments = $calculatedData->getDonorSegmentOptions();
    $this->assign('segments', $segments);

    $statuses = $calculatedData->getDonorStatusOptions();
    $this->assign('statuses', $statuses);
    parent::run();
  }

}

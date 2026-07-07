<?php

use CRM_Wmf_ExtensionUtil as E;

class CRM_Wmf_Page_Segment extends CRM_Core_Page {

  /**
   * @throws \CRM_Core_Exception
   */
  public function run() {
    $calculatedData = new \Civi\WMFHook\CalculatedData();

    $oldSegments = $calculatedData->getOldDonorSegmentOptions();
    $this->assign('oldSegments', $oldSegments);

    $oldStatuses = $calculatedData->getOldDonorStatusOptions();
    $this->assign('oldStatuses', $oldStatuses);

    $segments = $calculatedData->getDonorSegmentOptions();
    $this->assign('segments', $segments);

    $statusTables = [
      'Overall status' => 'donor_status_overall',
      'OTG status' => 'donor_status_otg',
      'Monthly recurring status' => 'donor_status_recur_month',
      'Annual recurring status' => 'donor_status_recur_year',
    ];
    foreach ($statusTables as $title => $table) {
      $statuses[$title] = $calculatedData->getSpecifiedDonorStatusOptions($table);
    }
    $this->assign("statuses", $statuses);

    parent::run();
  }

}

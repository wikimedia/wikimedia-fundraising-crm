<?php

class CRM_DedupeReview_Page_Batch_Index extends CRM_DedupeReview_Page_Batch_Base {
    /**
     * Show a list of batches assigned to the current user
     */
    function run() {
        $breadCrumb = array( array(
            'title' => ts( 'Dedupe Review' ),
            'url' => CRM_Utils_System::url( 'civicrm/dedupe_review' ),
        ) );
        CRM_Utils_System::appendBreadCrumb( $breadCrumb );

        $batchFetcher = new CRM_DedupeReview_BAO_ReviewBatch();
        $batchFetcher->setUserFilter();
        $assignedBatches = $batchFetcher->fetch();

        $table_rows = array();
        foreach ( $assignedBatches as $row ) {
            $name = ( $row['batch_name'] ? $row['batch_name'] : "BATCH-{$row['batch_id']}" );
            $batchLink = CRM_Utils_System::href( $name, 'civicrm/dedupe_review/batch', array( 'batch_id' => $row['batch_id'] ) );
            $table_rows[] = array(
                'batchLink' => $batchLink,
                'total' => $row['total'],
                'completed' => 'No',
            );
        }
          //'empty' => "You have nothing assigned to you.",
          //'caption' => t( 'Donor review batches assigned to you' ),

        $this->assign( 'tableRows', $table_rows );

        return parent::run();
    }
}

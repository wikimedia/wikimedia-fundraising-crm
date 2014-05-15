<?php

/**
 * Display a summary table of review work, grouped by machine-recommended action
 */
class CRM_DedupeReview_Page_Overview extends CRM_Core_Page {
    // TODO config <- param
    const BATCH_SIZE = 100;

    function getBAOName() {
        return 'CRM_DedupeReview_BAO_ReviewQueue';
    }

    function run() {
        $batchFetcher = new CRM_DedupeReview_BAO_ReviewBatch();
        $batchFetcher->setUserFilter();
        $assignedBatches = $batchFetcher->fetch();

        if ( $assignedBatches ) {
            $this->assign( 'numAssigned', count( $assignedBatches ) );
            $this->assign( 'myBatchesUrl', CRM_Utils_System::url( "civicrm/dedupe_review/batches" ) );
        }

        $queue = new CRM_DedupeReview_BAO_ReviewQueue();
        $rows = $queue->getSummary();

        $table_rows = array();
        foreach ( $rows as $row ) {
            $gimmeBatchLink = CRM_Utils_System::href(
                t( "Assign me a batch" ),
                "civicrm/dedupe_review/assign",
                array( "action_id" => $row['action_id'] )
            );
            $table_rows[] = array(
                'label_link' => $row['action'],
                'total' => $row['count'],
                'unassigned' => $row['unassigned'],
                'links' => implode( ' | ', array( $gimmeBatchLink ) ),
            );
        }
        $this->assign( 'dupe_categories', $table_rows );
          //'empty' => "No donors to review!  Be very afraid.",
          //'caption' => t( 'Donor review summary, by action' ),

        return parent::run();
    }
}

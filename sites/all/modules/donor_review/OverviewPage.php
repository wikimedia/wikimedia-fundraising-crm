<?php
namespace DonorReview;

class OverviewPage {
    const BATCH_SIZE = 100;

    /**
     * Display a summary table of review work, grouped by machine-recommended action
     */
    static function getForm() {
        civicrm_initialize();

        $batchFetcher = new ReviewBatch();
        $batchFetcher->setUserFilter();
        $assignedBatches = $batchFetcher->fetch();

        if ( $assignedBatches ) {
            $form['numAssigned'] = array(
                '#markup' => t("You have :num batches !link already.", array(
                    ':num' => count( $assignedBatches ),
                    '!link' => l( "assigned to you", "admin/donor_review/batches" ),
                ) ),
            );
        }

        $queue = new ReviewQueue();
        $rows = $queue->getSummary();

        $table_rows = array();
        foreach ( $rows as $row ) {
            $gimmeBatchLink = l( "Assign me a batch", "admin/donor_review/assign/{$row['action_id']}" );
            $table_rows[] = array(
                $row['action'],
                $row['count'],
                $row['unassigned'],
                implode( ' | ', array( $gimmeBatchLink ) ),
            );
        }
        $headers = array( 'Recommended action', 'Total', 'Unassigned', '', );
        $queue_html = theme_table( array(
          'header' => $headers,
          'rows' => $table_rows,
          'empty' => "No donors to review!  Be very afraid.",
          'attributes' => array(),
          'caption' => t( 'Donor review summary, by action' ),
          'colgroups' => array(),
          'sticky' => true,
        ) );

        $form['summary'] = array(
            '#markup' => $queue_html,
        );
        return $form;
    }

    /**
     * Create a work batch of the given machine-recommended action type.
     *
     * Redirects to the newly created batch, or back to the overview page.
     *
     * @param int $actionId DB ID of the action
     * @param int $batchSize maximum preferred number of items in the batch
     */
    static function assignBatch( $actionId, $batchSize = OverviewPage::BATCH_SIZE ) {
        global $user;

        $actionId = intval( $actionId );
        $batchSize = intval( $batchSize );

        $batchId = db_insert( 'donor_review_batch' )
            ->fields( array(
                'assigned_to' => $user->uid,
            ) )
            ->execute();

        // MySQL only
        $result = db_query( "
            UPDATE {donor_review_queue} SET
                assigned_to_batch = :batch_id
            WHERE
                assigned_to_batch IS NULL
                AND action_id = :action_id
            LIMIT " . $batchSize,
        array(
            ':batch_id' => $batchId,
            ':action_id' => $actionId,
        ) );

        $numRows = $result->rowCount();

        if ( !$numRows ) {
            db_delete( 'donor_review_batch' )
                ->condition( 'id', $batchId );

            drupal_set_message( "Nothing available, sorry.", 'error' );
            drupal_goto( "admin/donor_review" );
        } else {
            drupal_set_message( "Congratulations!  You were assigned {$numRows} records.", 'status' );
            drupal_goto( "admin/donor_review/batch/{$batchId}" );
        }
    }

}

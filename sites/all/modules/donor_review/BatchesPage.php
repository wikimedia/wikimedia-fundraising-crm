<?php
namespace DonorReview;

class BatchesPage {
    /**
     * Show a list of batches assigned to the current user
     */
    static function getForm( $form, $formState ) {
        civicrm_initialize();

        $batchFetcher = new ReviewBatch();
        $batchFetcher->setUserFilter();
        $assignedBatches = $batchFetcher->fetch();

        $headers = array( 'Batch', 'Total', 'Completed', );
        $table_rows = array();
        foreach ( $assignedBatches as $row ) {
            $name = ( $row['batch_name'] ? $row['batch_name'] : "BATCH-{$row['batch_id']}" );
            $batchLink = l( $name, "admin/donor_review/batch/{$row['batch_id']}" );
            $table_rows[] = array(
                $batchLink,
                $row['total'],
                'No',
            );
        }
        $batch_index_html = theme_table( array(
          'header' => $headers,
          'rows' => $table_rows,
          'empty' => "You have nothing assigned to you.",
          'attributes' => array(),
          'caption' => t( 'Donor review batches assigned to you' ),
          'colgroups' => array(),
          'sticky' => true,
        ) );

        $form['summary'] = array(
            '#markup' => $batch_index_html,
        );
        return $form;
    }
}

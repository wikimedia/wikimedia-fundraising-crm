<?php
namespace DonorReview;

class ReviewBatch {
    protected $userFilter;

    function setUserFilter( $userId = null ) {
        global $user;
        if ( $userId === null ) {
            $userId = $user->uid;
        }

        $this->userFilter = $userId;
    }

    /**
     * Get assigned batches
     *
     * @return array batches, having this structure:
     *     batchId: int
     *     userId: int
     */
    function fetch() {
        $query = db_select( 'donor_review_batch' )
            ->fields( 'donor_review_batch' );

        if ( $this->userFilter ) {
            $query->condition( 'assigned_to', $this->userFilter );
        }

        $query->join( 'donor_review_queue', 'donor_review_queue', 'donor_review_queue.assigned_to_batch = donor_review_batch.id' );
        $query->fields( 'donor_review_queue' )
            ->addExpression( 'COUNT(*)', 'total' );
        $query->groupBy( 'assigned_to_batch' );

        $result = $query->execute();

        $rows = array();
        while ( $row = $result->fetchAssoc() ) {
            $rows[] = array(
                'batch_name' => $row['title'],
                'batch_id' => $row['id'],
                'user_id' => $row['assigned_to'],
                'total' => $row['total'],
            );
        }
        return $rows;
    }

    /**
     * Can the current user edit this batch?
     *
     * @param int $batchId
     *
     * @return bool
     */
    static function canEdit( $batchId ) {
        global $user;

        $result = db_select( 'donor_review_batch' )
            ->fields( 'donor_review_batch' )
            ->condition( 'assigned_to', $user->uid )
            ->execute();

        return count( $result ) > 0;
    }
}

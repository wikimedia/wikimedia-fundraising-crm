<?php

/**
 * A list of review items, assigned to a user for review
 */
class CRM_DedupeReview_BAO_ReviewBatch {
    protected $userFilter;

    // FIXME: not a civi paradigm
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
        $values = array();
        $value_index = 1;
        $conditions = array( "true" );

        if ( $this->userFilter ) {
            $conditions[] = "assigned_to = %{$value_index}";
            $values[$value_index] = array( $this->userFilter, 'Integer' );
            $value_index++;
        }

        $where_clause = "where " . join( " and ", $conditions );
        $sql = "
select dedupe_review_batch.*, count(*) as total
from dedupe_review_batch
join dedupe_review_queue
    on dedupe_review_queue.assigned_to_batch = dedupe_review_batch.id
{$where_clause}
group by
    assigned_to_batch
";
        $result = CRM_Core_DAO::executeQuery( $sql, $values );

        $rows = array();
        while ( $result->fetch() ) {
            $rows[] = array(
                // TODO: assign titles to make special batches obvious
                //'batch_name' => $result->title,
                'batch_name' => "BATCH-{$result->id}",
                'batch_id' => $result->id,
                'user_id' => $result->assigned_to,
                'total' => $result->total,
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

        $sql = "
select *
from dedupe_review_batch
where
    id = %1
    and assigned_to = %2
";
        $result = CRM_Core_DAO::executeQuery( $sql, array(
            1 => array( $batchId, 'Integer' ),
            2 => array( $user->uid, 'Integer' ),
        ) );
        return $result->fetch();
    }

    /**
     * Create a new batch and assign to the logged-in user
     *
     * @param CRM_Core_DAO $dao List of queue items to populate this batch
     *
     * @return integer|null New batch ID or null on failure (FIXME)
     */
    static function assignBatch( $dao ) {
        global $user;

        $values = array(
            1 => array( $user->uid, 'Integer' ),
        );
        $sql = "
insert into dedupe_review_batch
set
    assigned_to = %1
";
        // FIXME
        // $batch = new CRM_DedupeReview_BAO_ReviewBatch();
        // $batch->assigned_to = $user->uid;
        // $batchId = $batch->insert();
        $result = CRM_Core_DAO::executeQuery( $sql, $values );
        $result = CRM_Core_DAO::executeQuery( "select last_insert_id() as id" );
        $result->fetch();
        $batchId = $result->id;

        $ids = array();
        while ( $dao->fetch() ) {
            $ids[] = $dao->id;
        }

        if ( !$ids ) {
            // FIXME: this is an inconsistent way to handle the failure.
            // We need to check the number of items actually assigned, not the
            // number attempted.  Also, we should throw an exception.
            return null;
        }

        $id_clause = join( ", ", $ids );
        $sql = "
update dedupe_review_queue
set
    assigned_to_batch = %1
where
    assigned_to_batch is null
    and id in ({$id_clause})
";
        $values = array(
            1 => array( $batchId, 'Integer' ),
        );
        CRM_Core_DAO::executeQuery( $sql, $values );

        // FIXME
        //$result = CRM_Core_DAO::executeQuery( "select row_count() as count" );
        //$numRows = $result->count;
        // announce batch
//
//        if ( !$numRows ) {
//            $values = array(
//                1 => array( $batchId, 'Integer' ),
//            );
//            $sql = "
//delete from dedupe_review_batch
//where
//    id = %1
//";
//            $result = CRM_Core_DAO::executeQuery( $sql, $values );
//            // $batch->delete();
//
//            drupal_set_message( "Nothing available, sorry.", 'error' );
//            drupal_goto( "admin/donor_review" );
//        } else {
//            drupal_set_message( "Congratulations!  You were assigned {$numRows} records.", 'status' );
//            drupal_goto( "admin/donor_review/batch/{$batchId}" );
//        }

        return $batchId;
    }
}

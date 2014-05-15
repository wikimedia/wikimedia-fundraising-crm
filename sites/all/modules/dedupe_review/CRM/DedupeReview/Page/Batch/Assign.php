<?php

/**
 * Assign a batch-worth and redirect
 *
 * @param int $action_id
 */
class CRM_DedupeReview_Page_Batch_Assign extends CRM_Core_Page {
    function run() {
        $batchSize = 50;
        $actionId = CRM_Utils_Request::retrieve('action_id', 'Positive',
            $this, false, 0, 'REQUEST'
        );

        # FIXME: figure out the division of responsibilities and be atomic
        $values = array(
            1 => array( $actionId, 'Integer' ),
        );
        $sql = "
select
    id
from dedupe_review_queue
where
    assigned_to_batch is null
    and action_id = %1
order by
    old_id desc,
    new_id asc
limit
    {$batchSize}
";
        $result = CRM_Core_DAO::executeQuery( $sql, $values );
        $batchId = CRM_DedupeReview_BAO_ReviewBatch::assignBatch( $result );
        
        if ( !$batchId ) {
            // TODO: message and redirect
            return CRM_Utils_System::redirect(
                CRM_Utils_System::url( 'civicrm/dedupe_review' )
            );
        }

        return CRM_Utils_System::redirect(
            CRM_Utils_System::url( 'civicrm/dedupe_review/batch', array( 'batch_id' => $batchId ) )
        );
    }
}

<?php

/**
 * Helpers for querying and manipulation review queue work items
 */
class CRM_DedupeReview_BAO_ReviewQueue {
    protected $actionFilter;
    protected $batchFilter;

    /**
     * Look up an action by name and return its ID.
     *
     * @param string $title action name
     * @return int DB ID
     */
    //FIXME: need a machine-readable name
    static function lookupActionId( $title ) {
        $sql = "
select *
from dedupe_review_action
where
    name = %1
";
        $values = array( 1 => array( $title, 'String' ) );
        $result = CRM_Core_DAO::executeQuery( $sql, $values );
        if ( $result->fetch() ) {
            return $result->id;
        }
    }

    /**
     * Fetch only items matching this review batch ID.
     *
     * @param int $batchId
     */
    function setBatchFilter( $batchId ) {
        $this->batchFilter = $batchId;
    }

    /**
     * Fetch only items matching this recommended action ID.
     *
     * @param int $batchId
     */
    function setActionFilter( $actionId ) {
        $this->actionFilter = $actionId;
    }

    /**
     * Get a page of donors
     *
     * TODO: not Magically paged by Drupal.
     *
     * @return array list of review items, each having fields:
     *     string item_id: queue item id
     *     string old_id: ID of the suspected original contact record
     *     string new_id: ID of the suspected new contact record
     *     string action: name of action
     */
    function fetch( $pageLength = 20 ) {
        $values = array();
        $value_index = 1;
        $conditions = array();

        if ( $this->actionFilter ) {
            $conditions[] = "action_id = %${value_index}";
            $values[$value_index] = array( $this->actionFilter, 'Integer' );
            $value_index++;
        }
        if ( $this->batchFilter ) {
            $conditions[] = "assigned_to_batch = %${value_index}";
            $values[$value_index] = array( $this->batchFilter, 'Integer' );
            $value_index++;
        }
        $where = "where " . join( " and ", $conditions );
        $sql = "
select
    dedupe_review_queue.*,
    dedupe_review_queue.id as item_id,
    dedupe_review_action.name
from dedupe_review_queue
left join dedupe_review_action
    on dedupe_review_action.id = dedupe_review_queue.action_id
{$where}
order by
    old_id asc,
    new_id asc
limit
    {$pageLength}
";
            // TODO: ->extend( 'PagerDefault' )
        $result = CRM_Core_DAO::executeQuery( $sql, $values );

        $rows = array();
        while ( $result->fetch() ) {
            $rows[] = array(
                'item_id' => $result->item_id,
                'old_id' => $result->old_id,
                'new_id' => $result->new_id,
                'action' => $result->name,
            );
        }
        return $rows;
    }

    /**
     * Get a review queue item
     *
     * @param integer $itemId
     *
     * @return array: See the schema in sql/install.sql for more information.
     *       integer id
     *       integer job_id
     *       integer old_id
     *       integer new_id
     *       string match_description
     *       integer action_id
     *       integer assigned_to_batch
     */
    static function get( $itemId ) {
        $sql = "
select *
from dedupe_review_queue
where
    id = %1
";
        $values = array( 1 => array( $itemId, 'Integer' ) );
        $result = CRM_Core_DAO::executeQuery( $sql, $values );
        if ( $result->fetch() ) {
            return (array)$result;
        }
    }

    /**
     * Get statistics about queue items, grouped by machine-recommended action
     *
     * @return array stats row having:
     *     int count: total number of items in this group
     *     int unassigned: number of items not assigned to a batch
     *     string action: name of action
     *     int action_id: db ID of action, for building an URL
     */
    function getSummary() {
        $sql = "
select
    dedupe_review_action.*,
    dedupe_review_action.id as action_id,
    count(*) as count,
    sum( if( assigned_to_batch is null, 1, 0 ) ) as unassigned
from dedupe_review_queue
left join dedupe_review_action
    on dedupe_review_action.id = dedupe_review_queue.action_id
group by
    action_id
";
        $result = CRM_Core_DAO::executeQuery( $sql );

        $rows = array();
        while ( $result->fetch() ) {
            $rows[] = array(
                'count' => $result->count,
                'unassigned' => $result->unassigned,
                'action' => $result->name,
                'action_id' => $result->action_id,
            );
        }
        return $rows;
    }
}

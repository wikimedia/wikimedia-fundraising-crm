<?php
namespace DonorReview;

/**
 * Helpers for querying and manipulation review queue work items
 */
class ReviewQueue {
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
        $result = db_select( 'donor_review_action' )
            ->fields( 'donor_review_action' )
            ->condition( 'name', $title )
            ->execute();
        foreach ( $result as $row ) {
            return $row->id;
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
     * Magically paged by Drupal.
     *
     * @return array list of review items, each having fields:
     *     string old_contact: html, links to the contact record
     *     string new_contact: html, links to the contact record
     *     string action: name of action
     *     array diff: structure having:
     *         string name: html diff between names
     *         string address: html diff between street addresses
     *         string email: html diff between emails
     *         string contributions: html listing of contributions
     */
    function fetch( $pageLength = 20 ) {
        $query = db_select( 'donor_review_queue' )
            ->fields( 'donor_review_queue' )
            ->orderBy( 'old_id', 'ASC' )
            ->orderBy( 'new_id', 'ASC' )
            ->extend( 'PagerDefault' )
            ->limit( $pageLength );

        $query->addField( 'donor_review_queue', 'id', 'item_id' );

        $query->join( 'donor_review_action', 'donor_review_action', 'donor_review_action.id = donor_review_queue.action_id' );
        $query->fields( 'donor_review_action' );

        if ( $this->actionFilter ) {
            $query->condition( 'action_id', $this->actionFilter );
        }
        if ( $this->batchFilter ) {
            $query->condition( 'assigned_to_batch', $this->batchFilter );
        }
        $result = $query->execute();

        $rows = array();
        while ( $row = $result->fetchAssoc() ) {
            $rows[] = array(
                'item_id' => $row['item_id'],
                'old_id' => $row['old_id'],
                'new_id' => $row['new_id'],
                'action' => $row['name'],
            );
        }
        return $rows;
    }

    static function get( $itemId ) {
        $result = db_select( 'donor_review_queue' )
            ->fields( 'donor_review_queue' )
            ->condition( 'id', $itemId )
            ->execute();

        return $result->fetchAssoc();
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
        $query = db_select( 'donor_review_queue' )
            ->fields( 'donor_review_queue' )
            ->groupBy( 'action_id' );
        $query->addExpression( 'COUNT(*)', 'count' );
        $query->addExpression( 'SUM( IF( assigned_to_batch IS NULL, 1, 0 ) )', 'unassigned' );
        $query->join( 'donor_review_action', 'donor_review_action', 'donor_review_action.id = donor_review_queue.action_id' );
        $query->fields( 'donor_review_action' );
        $result = $query->execute();

        $rows = array();
        while ( $row = $result->fetchAssoc() ) {
            $rows[] = array(
                'count' => $row['count'],
                'unassigned' => $row['unassigned'],
                'action' => $row['name'],
                'action_id' => $row['action_id'],
            );
        }
        return $rows;
    }
}

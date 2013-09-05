<?php

class ReviewQueue {
    function fetch( $pageLength = 20 ) {
        $result = db_select( 'donor_review_queue' )
            ->fields( 'donor_review_queue' )
            ->orderBy( 'new_id', 'ASC' )
            ->extend( 'PagerDefault' )
            ->limit( $pageLength )
            ->execute();

        $rows = array();
        while ( $row = $result->fetchAssoc() ) {
            # TODO: linkify
            $rows[] = array(
                $row['old_id'],
                $row['new_id'],
                $row['action'],
                DedupeDiff::diff($row['old_id'], $row['new_id']),
            );
        }
        return $rows;
    }
}

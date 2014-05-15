<?php

class CRM_DedupeReview_Page_Ajax {
    function run() {
        $item = CRM_Utils_Array::value( 'item', $_GET );
        $success = preg_match( '/^([a-z]+)-([0-9]+)$/', $item, $matches );
        if ( !$success ) {
            throw new Exception( "Bad item parameter {{$item}}" );
        }
        $scope = $matches[1];
        $item_id = $matches[2];

        $queue_item = CRM_DedupeReview_BAO_ReviewQueue::get( $item_id );
        if ( !$queue_item ) {
            throw New Exception( "Bad queue item ID: $item_id" );
        }
        if ( !CRM_DedupeReview_BAO_ReviewBatch::canEdit( $queue_item['assigned_to_batch'] ) ) {
            throw New Exception( "You don't own the batch this item has been assigned to." );
        }

        $operation = CRM_Utils_Array::value( 'operation', $_GET );

        switch ( $scope ) {
        case 'item':
            if ( $operation === 'confirm' ) {
                CRM_DedupeReview_Crm::clearTag( $queue_item['new_id'], 'Manually rejected action' );
                CRM_DedupeReview_Crm::clearTag( $queue_item['new_id'], 'Needs rereview' );

                CRM_DedupeReview_Crm::setTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );
            } elseif ( $operation === 'exclude' ) {
                // TODO: the UI should be fixed to that the "rereview" flag can be set
                // independently of other flags.  Although, choosing to rereview should
                // include the "exclude" action...
                CRM_DedupeReview_Crm::clearTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );

                CRM_DedupeReview_Crm::setTag( $queue_item['new_id'], 'Manually rejected action' );
            } elseif ( $operation === 'rereview' ) {
                CRM_DedupeReview_Crm::clearTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );

                CRM_DedupeReview_Crm::setTag( $queue_item['new_id'], 'Needs rereview' );
            }
            break;
        case 'email':
        case 'name':
        case 'address':
        case 'language':
            $revert_tag = "Manually reviewed - Revert {$scope}";

            // TODO: ripple changes
            if ( $operation === 'revert' ) {
                CRM_DedupeReview_Crm::setTag( $queue_item['new_id'], $revert_tag );
            } elseif ( $operation === 'update' ) {
                CRM_DedupeReview_Crm::clearTag( $queue_item['new_id'], $revert_tag );
            } else {
                throw new Exception( "Unknown operation {{$operation}}" );
            }
            break;
        default:
            throw new Exception( "Unknown scope {{$scope}}" );
        }

        header('Content-Type: text/javascript');
        echo json_encode( array( 'success' => true ) );
        CRM_Utils_System::civiExit();
    }
}

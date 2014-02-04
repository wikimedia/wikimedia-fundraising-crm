<?php
namespace DonorReview;

class ReviewPage {
    function donor_review_batch_complete( $batchId ) {
        // TODO mark all implicitly confirmed rows
    }

    /**
     * Display a table of review items from this batch.
     *
     * @param int $batchId
     */
    static function getForm( $form, $formState, $batchId ) {
        civicrm_initialize();

        $batchId = intval( $batchId );

        $dir = drupal_get_path( 'module', 'donor_review' );
        drupal_add_css( $dir . '/donor_review.css' );
        drupal_add_js( $dir . '/donor_review.js' );

        if ( module_exists( 'advanced_help' ) ) {
            // FIXME: grr, it nudges the table
            $form['item_help_icon'] = array(
                '#theme' => 'advanced_help_topic',
                '#module' => 'donor_review',
                '#topic' => 'review',
            );
        }

        if ( !ReviewBatch::canEdit( $batchId ) ) {
            drupal_set_message( t( "You are not assigned to this batch.  Do not pass go." ), 'warning' );
        }

        $rereviewTag = Crm::getTagId( 'Needs rereview' );
        $excludedTag = Crm::getTagId( 'Manually rejected action' );
        $confirmedTag = Crm::getTagId( 'Manually reviewed - Perform action' );
        $revertEmailTag = Crm::getTagId( 'Manually reviewed - Revert email' );
        $revertNameTag = Crm::getTagId( 'Manually reviewed - Revert name' );
        $revertAddressTag = Crm::getTagId( 'Manually reviewed - Revert address' );
        $revertLanguageTag = Crm::getTagId( 'Manually reviewed - Revert language' );

        $queue = new ReviewQueue();
        $queue->setBatchFilter( $batchId );
        $rows = $queue->fetch();

        $headers = array( t( 'Old' ), t( 'New' ), t( 'Email' ), t( 'Name' ), t( 'Address' ), t( 'Language' ), t( 'Contributions' ), '' );
        $table_rows = array();
        foreach ( $rows as $row ) {
            $old_contact = Crm::getContact( $row['old_id'] );
            $new_contact = Crm::getContact( $row['new_id'] );
            $diff = DedupeDiff::diff( $old_contact, $new_contact );

            $rowClass = ReviewPage::buildTagClasses( $new_contact->tags, array(
                $excludedTag => 'exclude',
                $rereviewTag => 'rereview',
            ) );
            $languageClass = ReviewPage::buildTagClasses( $new_contact->tags,
                array( $revertLanguageTag => 'revert' ),
                array( 'diff' )
            );
                
            $table_rows[] = array(
                'id' => "item-{$row['item_id']}",
                'class' => $rowClass,
                'data' => array(
                    Crm::getContactLink( $row['old_id'] ),
                    Crm::getContactLink( $row['new_id'] ),
                    array(
                        'data' => $diff['email'],
                        'id' => "email-{$row['item_id']}",
                    ),
                    array(
                        'data' => $diff['name'],
                        'id' => "name-{$row['item_id']}",
                    ),
                    array(
                        'data' => "{$diff['address']} {$diff['country']}",
                        'id' => "address-{$row['item_id']}",
                    ),
                    array(
                        'data' => $diff['language'],
                        'class' => $languageClass,
                        'id' => "language-{$row['item_id']}",
                    ),
                    $diff['contributions'],
                    array(
                        'class' => 'buttons',
                        'data' => array(
                            'exclude-button' => array(
                                '#type' => 'button',
                                '#value' => t( 'Exclude' ),
                                '#name' => 'exclude',
                            ),
                            'confirm-button' => array(
                                '#type' => 'button',
                                '#value' => t( 'Include' ),
                                '#name' => 'confirm',
                            ),
                            'rereview-button' => array(
                                '#type' => 'button',
                                '#value' => t( 'Rereview' ),
                                '#name' => 'rereview',
                            ),
                        ),
                    ),
                ),
            );
        }
        $queue_html = theme_table( array(
          'header' => $headers,
          'rows' => $table_rows,
          'empty' => "No donors to review!  Be very afraid.",
          'attributes' => array(
            'id' => 'donor_review_table',
            'class' => array( 'touch-table' ),
          ),
          'caption' => t( 'Donor review' ),
          'colgroups' => array(),
          'sticky' => true,
        ) ).theme( 'pager' );

        $form['queue'] = array(
            '#markup' => $queue_html,
        );
        return $form;
    }

    // FIXME: so expensive!
    static function ajaxController( $item, $operation ) {
        $success = preg_match( '/^([a-z]+)-([0-9]+)$/', $item, $matches );
        if ( !$success ) {
            throw new Exception( "Bad parameter {{$item}}" );
        }
        $scope = $matches[1];
        $item_id = $matches[2];

        $queue_item = ReviewQueue::get( $item_id );
        if ( !$queue_item ) {
            throw New Exception( "Bad queue item ID: $item_id" );
        }
        if ( !ReviewBatch::canEdit( $queue_item['assigned_to_batch'] ) ) {
            throw New Exception( "You don't own the batch this item has been assigned to." );
        }

        switch ( $scope ) {
        case 'item':
            if ( $operation === 'confirm' ) {
                Crm::clearTag( $queue_item['new_id'], 'Manually rejected action' );
                Crm::clearTag( $queue_item['new_id'], 'Needs rereview' );

                Crm::setTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );
            } elseif ( $operation === 'exclude' ) {
                Crm::clearTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );

                Crm::setTag( $queue_item['new_id'], 'Manually rejected action' );
            } elseif ( $operation === 'rereview' ) {
                Crm::clearTag( $queue_item['new_id'], 'Manually reviewed - Perform action' );

                Crm::setTag( $queue_item['new_id'], 'Needs rereview' );
            }
            break;
        case 'email':
        case 'name':
        case 'address':
        case 'language':
            $revert_tag = "Manually reviewed - Revert {$scope}";

            // TODO: ripple changes
            if ( $operation === 'revert' ) {
                Crm::setTag( $queue_item['new_id'], $revert_tag );
            } elseif ( $operation === 'update' ) {
                Crm::clearTag( $queue_item['new_id'], $revert_tag );
            } else {
                throw new Exception( "Unknown operation {{$operation}}" );
            }
            break;
        default:
            throw new Exception( "Unknown scope {{$scope}}" );
        }

        drupal_json_output( array( 'success' => true ) );
    }

    static protected function buildTagClasses( $tags, $tagClassMap, $consts = array() ) {
        $out = array();
        foreach ( $tagClassMap as $tagId => $class ) {
            if ( in_array( $tagId, $tags ) ) {
                $out[] = $class;
            }
        }
        return array_merge( $consts, $out );
    }
}

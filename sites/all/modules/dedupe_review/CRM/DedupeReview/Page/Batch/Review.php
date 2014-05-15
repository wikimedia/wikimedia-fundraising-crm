<?php

/**
 * Display a table of review items from this batch.
 *
 * @param int $batchId
 */
class CRM_DedupeReview_Page_Batch_Review extends CRM_Core_Page {
    function run() {
        $batchId = CRM_Utils_Request::retrieve('batch_id', 'Positive',
            $this, false, 0, 'REQUEST'
        );

        $breadCrumb = array( array(
            'title' => ts( 'Dedupe Review' ),
            'url' => CRM_Utils_System::url( 'civicrm/dedupe_review' ),
        ) );
        CRM_Utils_System::appendBreadCrumb( $breadCrumb );

        CRM_Utils_System::setTitle( ts('Review Batch: %1', array( 1 => "BATCH-{$batchId}" ) ) );

        if ( !CRM_DedupeReview_BAO_ReviewBatch::canEdit( $batchId ) ) {
            drupal_set_message( ts( "You are not assigned to this batch.  Do not pass go." ), 'warning' );
        }

        $rereviewTag = CRM_DedupeReview_Crm::getTagId( 'Needs rereview' );
        $excludedTag = CRM_DedupeReview_Crm::getTagId( 'Manually rejected action' );
        $confirmedTag = CRM_DedupeReview_Crm::getTagId( 'Manually reviewed - Perform action' );
        $revertEmailTag = CRM_DedupeReview_Crm::getTagId( 'Manually reviewed - Revert email' );
        $revertNameTag = CRM_DedupeReview_Crm::getTagId( 'Manually reviewed - Revert name' );
        $revertAddressTag = CRM_DedupeReview_Crm::getTagId( 'Manually reviewed - Revert address' );
        $revertLanguageTag = CRM_DedupeReview_Crm::getTagId( 'Manually reviewed - Revert language' );

        $queue = new CRM_DedupeReview_BAO_ReviewQueue();
        $queue->setBatchFilter( $batchId );
        $rows = $queue->fetch();

        $table_rows = array();
        foreach ( $rows as $row ) {
            $old_contact = CRM_DedupeReview_Crm::getContact( $row['old_id'] );
            $new_contact = CRM_DedupeReview_Crm::getContact( $row['new_id'] );
            $diff = CRM_DedupeReview_DedupeDiff::diff( $old_contact, $new_contact );

            $rowClass = self::buildTagClasses( $new_contact->tags, array(
                $excludedTag => 'exclude',
                $rereviewTag => 'rereview',
            ) );
            $languageClass = self::buildTagClasses( $new_contact->tags,
                array( $revertLanguageTag => 'revert' ),
                array( 'diff' )
            );

            $table_rows[] = array(
                'itemId' => $row['item_id'],
                'class' => implode( ' ', $rowClass ),
                'oldId' => CRM_DedupeReview_Crm::getContactLink( $row['old_id'] ),
                'newId' => CRM_DedupeReview_Crm::getContactLink( $row['new_id'] ),
                'email' => $diff['email'],
                'name' => $diff['name'],
                'address' => "{$diff['address']} {$diff['country']}",
                'languageClass' => implode( ' ', $languageClass ),
                'language' => $diff['language'],
                'contributions' => $diff['contributions'],
            );
        }
        $this->assign( 'tableRows', $table_rows );
          //'empty' => "No donors to review!  Be very afraid.",
          //'caption' => t( 'Donor review' ),
        //) ).theme( 'pager' );

        return parent::run();
    }

    /**
     * Helper to produce a list of classes by comparing contact tags
     *
     * @param array $tags Tags attached to a contact
     * @param array $tagClassMap map from tag to corresponding class
     * @param array $consts always add these classes
     */
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

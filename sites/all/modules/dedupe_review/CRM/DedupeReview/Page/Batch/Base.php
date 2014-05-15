<?php

/**
 * Stupid base class
 */
abstract class CRM_DedupeReview_Page_Batch_Base extends CRM_Core_Page {
    function getBAOName() {
        return 'CRM_DedupeReview_BAO_ReviewBatch';
    }
}

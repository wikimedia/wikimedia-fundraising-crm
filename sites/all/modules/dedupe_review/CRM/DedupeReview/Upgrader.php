<?php

/**
 * Collection of upgrade steps
 */
class CRM_DedupeReview_Upgrader extends CRM_DedupeReview_Upgrader_Base {
  public function install() {
    $this->executeSqlFile('sql/install.sql');
  }
}

<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * Upgrade logic for the 5.63.x series.
 *
 * Each minor version in the series is handled by either a `5.63.x.mysql.tpl` file,
 * or a function in this class named `upgrade_5_63_x`.
 * If only a .tpl file exists for a version, it will be run automatically.
 * If the function exists, it must explicitly add the 'runSql' task if there is a corresponding .mysql.tpl.
 *
 * This class may also implement `setPreUpgradeMessage()` and `setPostUpgradeMessage()` functions.
 */
class CRM_Upgrade_Incremental_php_FiveSixtyThree extends CRM_Upgrade_Incremental_Base {

  /**
   * Upgrade step; adds tasks including 'runSql'.
   *
   * @param string $rev
   *   The version number matching this function name
   *
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function upgrade_5_63_alpha1(string $rev): void {
    $this->addTask('Add name column  to civicrm_contribution_page', 'addColumn', 'civicrm_contribution_page',
      'name', "varchar(255) NULL COMMENT 'Unique name for identifying contribution page'");
    $this->addTask(ts('Upgrade DB to %1: SQL', [1 => $rev]), 'runSql', $rev);

    // Campaign indexes
    $this->addTask(ts('Drop index %1', [1 => 'civicrm_campaign.UI_campaign_type_id']), 'dropIndex', 'civicrm_campaign', 'UI_campaign_type_id');
    $this->addTask(ts('Create index %1', [1 => 'civicrm_campaign.index_campaign_type_id']), 'addIndex', 'civicrm_campaign', 'campaign_type_id', 'index');
    $this->addTask(ts('Drop index %1', [1 => 'civicrm_campaign.UI_campaign_status_id']), 'dropIndex', 'civicrm_campaign', 'UI_campaign_status_id');
    $this->addTask(ts('Create index %1', [1 => 'civicrm_campaign.index_status_id']), 'addIndex', 'civicrm_campaign', 'status_id', 'index');
    $this->addTask('Add default value to civicrm_campaign.created_date', 'alterColumn', 'civicrm_campaign', 'created_date', "datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time that Campaign was created.'");
    $this->addTask('Enable component extensions', 'enableExtensions');

    $this->addTask('Make ContributionPage.name required', 'alterColumn', 'civicrm_contribution_page', 'name', "varchar(255) NOT NULL COMMENT 'Unique name for identifying contribution page'");
    $this->addTask('Make ContributionPage.title required', 'alterColumn', 'civicrm_contribution_page', 'title', "varchar(255) NOT NULL COMMENT 'Contribution Page title. For top of page display'", TRUE);
    $this->addTask('Make ContributionPage.frontend_title required', 'alterColumn', 'civicrm_contribution_page', 'frontend_title', "varchar(255) NOT NULL COMMENT 'Contribution Page Public title'", TRUE);
  }

  /**
   * Since I hit a bug with core code this enables the new core extensions for WMF directly.
   *
   * Ref https://lab.civicrm.org/dev/core/-/issues/4424 for the bug details.
   *
   * Note our components are
   *
   *  a:6:{i:0;s:9:"CiviEvent";i:1;s:14:"CiviContribute";i:2;s:8:"CiviMail";i:3;s:10:"CiviPledge";i:4;s:10:"CiviReport";i:5;s:12:"CiviCampaign";}
   * @return bool
   */
  public function enableExtensions() {
    civicrm_api3('Extension', 'install', [
      'keys' => ["civi_report", "civi_contribute", "civi_event", "civi_mail", "civi_pledge", "civi_campaign"],
    ]);
    return TRUE;
  }

}

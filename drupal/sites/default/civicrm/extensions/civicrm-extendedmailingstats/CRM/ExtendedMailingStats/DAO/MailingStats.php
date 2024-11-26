<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $mailing_id
 * @property string $report_id
 * @property bool $is_multiple_record
 * @property string $mailing_name
 * @property bool|string $is_completed
 * @property string $created_date
 * @property string $start
 * @property string $finish
 * @property string $recipients
 * @property string $delivered
 * @property float|string $send_rate
 * @property string $bounced
 * @property string $blocked
 * @property string $suppressed
 * @property string $abuse_complaints
 * @property string $opened_total
 * @property string $opened_unique
 * @property string $unsubscribed
 * @property string $forwarded
 * @property string $clicked_total
 * @property string $clicked_unique
 * @property string $trackable_urls
 * @property string $clicked_contribution_page
 * @property string $contribution_count
 * @property float|string $contribution_total
 */
class CRM_ExtendedMailingStats_DAO_MailingStats extends CRM_ExtendedMailingStats_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_mailing_stats';

}

<?php
/**
 * Bug T199748 remove duplicate mailing provider entries.
 *
 * We have a problem where by we have duplicate entries for many events in the
 * civicrm_mailing_provider_data table due to a bug.
 *
 * We unique index mailing provider data using contact_identifier + recipient_date_time
 * + event type so incorrect handling of recipient_date_time leads to duplicate rows.
 *
 * For example we have this data in the csv from Silverpop
 * Recipient Id   | Mailing Id |Event Type |Event Timestamp        |ContactID
 * 117413221292   | 53769163,  |Sent       | 05/03/2017 22:02:39   |8152134
 *
 * vs in CiviCRM we have
 *
 * select contact_identifier, mailing_identifier, event_type, recipient_action_datetime, contact_id from civicrm_mailing_provider_data
 * WHERE contact_id = 8152134 AND mailing_identifier='sp53769163' and event_type = 'sent';
 * +--------------------+--------------------+------------+---------------------------+------------+
 * | contact_identifier | mailing_identifier | event_type | recipient_action_datetime | contact_id |
 * +--------------------+--------------------+------------+---------------------------+------------+
 * | 117413221292       | sp53769163         | Sent       | 2017-05-03 03:02:39       | 8152134    |
 * | 117413221292       | sp53769163         | Sent       | 2017-05-03 10:02:39       | 8152134    |
 *
 * The timestamp in the csv can be reasonably understood to be in GMT as that is specificed for a different timestamp
 * field in the documentation.
 *
 * Most recent case of this is 2017-11-04 14:51:07.
 * SELECT   max( mp.recipient_action_datetime)   FROM civicrm_mailing_provider_data mp
 * INNER JOIN civicrm_mailing_provider_data mp2    ON mp.contact_identifier = mp2.contact_identifier
 * AND mp.mailing_identifier = mp2.mailing_identifier    AND mp.event_type = mp2.event_type  AND mp.recipient_action_datetime < mp2.recipient_action_datetime
 * WHERE mp2.`contact_identifier` IS NOT NULL    AND TIMESTAMPDIFF(MINUTE,mp.recipient_action_datetime , mp2.recipient_action_datetime) =4 20
 * AND mp.event_type = 'Sent' AND mp.recipient_action_datetime > '2017-08-01';
 *
 * I checked recent entries manually and found that the timestamp is correct.
 *
 * I'm unsure why older ones are not, in that I recall we had a bug getting am & pm mixed - which explains
 * part but not all of the issue. Perhaps I ran it under my own account & picked up NZ  tz? Some of the
 * data below seems suspiciously related to my tz....
 *
 * We have deployed https://github.com/eileenmcnaughton/omnimail-silverpop/commit/77e10a4486303044de0310c6340f44fb9e89dbe4
 * which should prevent issues going forwards.
 *
 * Using a specific record I have these 2 rows in civicrm_mailing_provider_data
 *
 * | 264901425416       | sp53674432         | 2017-05-14 12:07:36       | Open       |
 * | 264901425416       | sp53674432         | 2017-05-14 19:07:36       | Open       |
 *
 * Using drush (drush cvapi Omnirecipient.get mail_provider=Silverpop start_date=2017-05-13 end_date=2017-05-18)
 * I pull the correct data and the timestamp is
 *
 * 2017-05-15 07:07:36
 *
 * This is 19 hours after the first entry and 12 after the second (which is the day before).
 *
 * Checking how this works with records on the other side of the yardarm I have this record from the csv (in GMT)
 *  05/15/2017 21:01:21
 * represented by
 *
 * | 262611795080       | sp53572624         | 2017-05-15 02:01:21       | Open       |
 * | 262611795080       | sp53572624         | 2017-05-15 09:01:21       | Open
 *
 * This is 19 hours AFTER the first entry and 12 AFTER the second.
 *
 * Another where the real time is 2017-05-17 19:34:34 & the second db time is 2017-05-17 07:34:34
 *
 * So we need to
 *
 * 1) delete the earlier entry
 * 2) add 12 hours to the second entry.
 * 3) after clean up look again at the data for any more systematic patterns
 *
 * This kinda ties in with the vaguely recalled issue we were converting with 'h' not 'H' so we have something
 * in the wrong tz & with hours being misrepresented.
 *
 * I am ALSO seeing non-systematic double sends - this appears so far to be 'genuine in that I think silverpop has it too.
 * eg. select contact_identifier, mailing_identifier, event_type, recipient_action_datetime, contact_id from civicrm_mailing_provider_data
 * WHERE contact_id = 6528133 AND mailing_identifier='sp55918697' and event_type = 'sent';
 * +--------------------+--------------------+------------+---------------------------+------------+
 * | contact_identifier | mailing_identifier | event_type | recipient_action_datetime | contact_id |
 * +--------------------+--------------------+------------+---------------------------+------------+
 * | 92544457720        | sp55918697         | Sent       | 2018-02-15 03:44:38       | 6528133    |
 * | 92544457720        | sp55918697         | Sent       | 2018-03-05 06:10:48       | 6528133    |
 * +--------------------+--------------------+------------+---------------------------+------------+
 * 2 rows in set (0.05 sec)
 *
 *
 * SAMPLE SQL INSERT FOR TESTING
 *
INSERT INTO `civicrm_mailing_provider_data` (`contact_identifier`, `mailing_identifier`, `email`, `event_type`, `recipient_action_datetime`, `contact_id`, `is_civicrm_updated`)
VALUES
('1', '2', NULL, 'Sent', '2016-08-07 05:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 11:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 12:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 18:51:42', NULL, 0);

 */

/**
* Implementation of hook_drush_command()
*/
function civicrm_repair_omnirecipients_drush_command() {
  $items = array();
  $items['civicrm-repair-omnirecipients'] = array(
    'description' => 'Repair omnirecipients with timestamp related duplication',
    'options' => array(
      'batch' => "Batch size",
      'threshold' => 'Threshold for aborting. If there are more than this number of contributions in the threshold period then abort.',
      'threshold_period' => 'Number of minutes in the threshold period',
    ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_repair_omnirecipients_drush_help($section) {
  switch ( $section ) {
  case 'drush:civicrm-repair-omnirecipients':
    return dt('Repair omnirecipients where duplicate records are 7 hours apart');
  }
}

/**
 * Repair Omnirecipient records.
 *
 * Read docblock at the start of this file.
 *
 * @throws \CiviCRM_API3_Exception
 */
function drush_civicrm_repair_omnirecipients() {
  module_invoke('civicrm', 'initialize');

  require_once 'drush_threshholds.php';
  $threshold = (int) drush_get_option('threshold');
  if ($threshold) {
    $thresholdNumberOfMinutes = (int) drush_get_option('threshold_period', 5);
    if (_drush_civicrm_queue_is_backed_up($threshold, $thresholdNumberOfMinutes)) {
      return;
    }
  }
  $batch_size = (integer) drush_get_option('batch', 5000);
  $startVariableName = 'civicrm_repair_omnirecipient_last_processed_timestamp';
  $startDate = variable_get($startVariableName, '2016-06-26');
  $endDate = drush_civicrm_repair_process_rows($startDate, $batch_size);
  variable_set($startVariableName, $endDate);
  drush_print("Processed omnirecipient fixes to $endDate");
}

/**
 * @param string $startDate
 * @param int $batch_size
 *
 * @return false|null|string
 */
function drush_civicrm_repair_process_rows($startDate, $batch_size = 5000) {
  $increment = '+ 5 days';
  $count = 0;
  $isHitLimit = FALSE;
  while (strtotime($startDate) < strtotime('now') && !$isHitLimit) {
    $endDate = date('Y-m-d H:i:s', strtotime($increment, strtotime($startDate)));
    $sql = "
      SELECT
        mp.contact_identifier, mp.recipient_action_datetime, mp.event_type,
        mp2.contact_identifier as ci2, mp2.recipient_action_datetime as date_time2, mp2.event_type as event_type2

      FROM civicrm_mailing_provider_data mp

      INNER JOIN civicrm_mailing_provider_data mp2
        ON mp.contact_identifier = mp2.contact_identifier
        AND mp.mailing_identifier = mp2.mailing_identifier
        AND mp.event_type = mp2.event_type  AND mp.recipient_action_datetime < mp2.recipient_action_datetime

      WHERE mp2.`contact_identifier` IS NOT NULL
      AND TIMESTAMPDIFF(MINUTE,mp.recipient_action_datetime , mp2.recipient_action_datetime) = 480 #8 hours exactly after daylight savings
      AND mp.event_type = mp2.event_type
      AND mp.recipient_action_datetime BETWEEN %1 AND %2
    ";

    $result = CRM_Core_DAO::executeQuery($sql, [
      1 => [$startDate, 'String'],
      2 => [$endDate, 'String']
    ]);
    while ($result->fetch() && !$isHitLimit) {
      $count++;
      $sqls = [];
      // Delete the row from the first record.
      $sqls[] = "
         DELETE FROM civicrm_mailing_provider_data
         WHERE
           contact_identifier = {$result->contact_identifier}
           AND recipient_action_datetime = '{$result->recipient_action_datetime}'
           AND event_type = '{$result->event_type}'
         ";
      $sqls[] = "
         UPDATE IGNORE civicrm_mailing_provider_data
         SET recipient_action_datetime = DATE_ADD(recipient_action_datetime, INTERVAL 13 HOUR)
         WHERE
           contact_identifier = {$result->ci2}
           AND recipient_action_datetime = '{$result->date_time2}'
           AND event_type = '{$result->event_type2}'
         ";
      foreach ($sqls as $sql) {
        CRM_Core_DAO::executeQuery($sql);
      }
      if ($count === $batch_size) {
        $isHitLimit = TRUE;
        // This won't move the saved start date var but next time there will be next rows in that set.
        $endDate = $startDate;
      }
    }
    $startDate = $endDate;
  }
  return $endDate;
}

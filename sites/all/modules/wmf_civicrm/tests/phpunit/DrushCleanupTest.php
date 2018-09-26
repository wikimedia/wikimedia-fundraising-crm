<?php
require_once __DIR__ . '/../../scripts/civicrm_repair_omnirecipients.drush.inc';

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class DrushCleanupTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $mysqlTimeZone;

  /**
   * Set up.
   *
   * Since we are validating time values on a timestamp field run in GMT to
   * ensure consistency.
   */
  public function setUp() {
    civicrm_initialize();
    $this->mysqlTimeZone = CRM_Core_DAO::singleValueQuery('SELECT @@TIME_ZONE');
    CRM_Core_DAO::singleValueQuery("SET TIME_ZONE='+00:00'");

    if (!isset($GLOBALS['_PEAR_default_error_mode'])) {
      // This is simply to protect against e-notices if globals have been reset by phpunit.
      $GLOBALS['_PEAR_default_error_mode'] = NULL;
      $GLOBALS['_PEAR_default_error_options'] = NULL;
    }
  }

  public function testDrushOmnirecipientRepair() {
    CRM_Core_DAO::executeQuery("
    INSERT INTO `civicrm_mailing_provider_data` (`contact_identifier`, `mailing_identifier`, `email`, `event_type`, `recipient_action_datetime`, `contact_id`, `is_civicrm_updated`)
VALUES
('1', '2', NULL, 'Sent', '2016-08-07 05:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 11:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 12:51:42', NULL, 0),
('1', '2', NULL, 'Sent', '2016-08-07 18:51:42', NULL, 0);
  ");

    drush_civicrm_repair_process_rows('2016-08-05');

    $result = $this->callAPISuccess('MailingProviderData', 'get', ['sequential' => 1, 'mail_provider' => 'Silverpop']);
    $this->assertEquals(2, $result['count']);
    // The second 2 rows above are kept with 12 hours added.
    $this->assertEquals('2016-08-08 00:51:42', $result['values'][0]['recipient_action_datetime']);
    $this->assertEquals('2016-08-08 06:51:42', $result['values'][1]['recipient_action_datetime']);
  }

  public function tearDown() {
    CRM_Core_DAO::singleValueQuery("SET TIME_ZONE='{$this->mysqlTimeZone}'");
    CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_mailing_provider_data WHERE recipient_action_datetime
      BETWEEN '2016-08-07' AND '2016-08-09'
   ");
  }
}

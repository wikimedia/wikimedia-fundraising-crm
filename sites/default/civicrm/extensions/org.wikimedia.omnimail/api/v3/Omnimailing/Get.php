<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 5/3/17
 * Time: 12:46 PM
 */

use Omnimail\Omnimail;
if (file_exists( __DIR__ . '/../../../vendor/autoload.php')) {
 require_once __DIR__ . '/../../../vendor/autoload.php';
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_omnimailing_get($params) {
  $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
  $mailerParameters = array(
    'StartTimeStamp' => strtotime($params['start_date']),
    'EndTimeStamp' => strtotime($params['end_date']),
  );

  $mailings = $mailer->getMailings($mailerParameters)->getResponse();
  $results = array();
  foreach ($mailings as $mailing) {
    try {
      $result = [
        'subject' => $mailing->getSubject(),
        'external_identifier' => $mailing->getMailingIdentifier(),
        'name' => $mailing->getName(),
        'scheduled_date' => $mailing->getScheduledDate(),
        'start_date' => $mailing->getSendStartDate(),
        'number_sent' => $mailing->getNumberSent(),
        'body_html' => $mailing->getHtmlBody(),
        'body_text' => $mailing->getTextBody(),
        'number_bounced' => $mailing->getNumberBounces(),
        'number_opened_total' => $mailing->getNumberOpens(),
        'number_opened_unique' => $mailing->getNumberUniqueOpens(),
        'number_unsubscribed' => $mailing->getNumberUnsubscribes(),
        'number_suppressed' => $mailing->getNumberSuppressedByProvider(),
        // 'forwarded'
        'number_blocked' => $mailing->getNumberBlocked(),
        // 'clicked_total' => $stats['NumGrossClick'],
        'number_abuse_complaints' => $mailing->getNumberAbuseReports(),
        'list_id' => $mailing->getListId(),
      ];

      foreach ($result as $key => $value) {
        // Assuming we might change provider and they might not return
        // all the above, we unset any not returned.
        if ($value === NULL) {
          unset($result[$key]);
        }
      }
      $results[] = $result;
    }
    catch (Exception $e) {
      // Continue. It seems we sometimes get back deleted emails which
      // should not derail the process.
    }
  }
  // We want these to fail hard (I think) so not in the try catch block.
    foreach ($results as $index => $result) {
      if (!empty($result['list_id'])) {
        // This is kinda just hacked in because it doesn't feel generic at the
        // moment .. pondering....
        $results[$index]['list_criteria'] = civicrm_api3('Omnihell', 'get', array_merge($params, [
          'list_id' => $result['list_id'],
        ]))['values'][0];
    }
  }
  return civicrm_api3_create_success($results);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnimailing_get_spec(&$params) {
  $params['username'] = array(
    'title' => ts('User name'),
  );
  $params['password'] = array(
    'title' => ts('Password'),
  );
  $params['mail_provider'] = array(
    'title' => ts('Name of Mailer'),
    'api.required' => TRUE,
  );
  $params['start_date'] = array(
    'title' => ts('Date to fetch from'),
    'api.default' => '1 week ago',
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  );
  $params['end_date'] = array(
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
    'api.default' => 'now',
  );

}

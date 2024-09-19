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
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_omnimailing_get($params) {
  // @todo - we should leverage the same code to do this as the Omnirecipient job.
  // I don't really want to do a restructure right now so a quick hack but a bit todo.
  date_default_timezone_set('UTC');
  CRM_Core_DAO::executeQuery("SET TIME_ZONE='+00:00'");

  /* @var \Omnimail\Silverpop\Mailer $mailer */
  $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
  $mailerParameters = [
    'StartTimeStamp' => strtotime($params['start_date']),
    'EndTimeStamp' => strtotime($params['end_date']),
  ];

  $mailings = $mailer->getMailings($mailerParameters)->getResponse();
  $results = [];
  foreach ($mailings as $mailing) {
    /* @var \Omnimail\Silverpop\Responses\Mailing $mailing */
    try {
      $result = [
        'subject' => $mailing->getSubject(),
        'external_identifier' => $mailing->getMailingIdentifier(),
        'name' => substr($mailing->getName(), 0, 128),
        'scheduled_date' => $mailing->getScheduledDate(),
        'start_date' => $mailing->getSendStartDate(),
        'number_sent' => $mailing->getNumberSent(),
        'number_bounced' => $mailing->getNumberBounces(),
        'number_opened_total' => $mailing->getNumberOpens(),
        'number_opened_unique' => $mailing->getNumberUniqueOpens(),
        'number_unsubscribed' => $mailing->getNumberUnsubscribes(),
        'number_suppressed' => $mailing->getNumberSuppressedByProvider(),
        // 'forwarded'
        'number_blocked' => $mailing->getNumberBlocked(),
        'number_clicked_total' => $mailing->getNumberClicked(),
        'number_clicked_unique' => $mailing->getNumberUniqueClicked(),
        'number_abuse_complaints' => $mailing->getNumberAbuseReports(),
        'list_id' => $mailing->getListId(),
        'body_html' => $mailing->getHtmlBody(),
        'body_text' => $mailing->getTextBody(),
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
    $results[$index]['list_criteria'] = '';
    if (!empty($result['list_id'])) {
      if (Civi::settings()->get('omnimail_omnihell_enabled')) {
        // This is kinda just hacked in because it doesn't feel generic at the
        // moment .. pondering....
        $results[$index]['list_criteria'] = civicrm_api3('Omnihell', 'get', array_merge($params, [
          'list_id' => $result['list_id'],
        ]))['values'][0];
      }
      try {
        // There are still some mysteries around the data retrievable this way.
        // Lets get & store both while both work. We can consolidate when we are comfortable.
        $results[$index]['list_string'] = $mailer->getQueryCriteria(['QueryIdentifier' => $result['list_id']])->getResponse()->getQueryCriteria();
      }
      catch (Exception $e) {
        // I saw a fail like Request failed: Query Id is not valid.
        // we should skip rather than crash the job
      }
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
  $params['username'] = [
    'title' => ts('User name'),
  ];
  $params['password'] = [
    'title' => ts('Password'),
  ];
  $params['mail_provider'] = [
    'title' => ts('Name of Mailer'),
    'api.default' => 'Silverpop',
  ];
  $params['start_date'] = [
    'title' => ts('Date to fetch from'),
    'api.default' => '1 week ago',
    'type' => CRM_Utils_Type::T_TIMESTAMP,
  ];
  $params['end_date'] = [
    'title' => ts('Date to fetch to'),
    'type' => CRM_Utils_Type::T_TIMESTAMP,
    'api.default' => 'now',
  ];

}

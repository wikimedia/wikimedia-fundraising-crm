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
    'timeout' => $params['timeout'],
  ];

  $mailings = (array) $mailer->getMailings($mailerParameters)->getResponse();
  $results = [];
  foreach ($mailings as $mailing) {
    /* @var \Omnimail\Silverpop\Responses\Mailing $mailing */
    try {
      $result = mapMailing($mailing) + ['is_multiple_report' => FALSE];
      if (!$params['is_include_text']) {
        unset($result['body_text'], $result['body_html']);
      }
      $results[] = $result;
    }
    catch (Exception $e) {
      // Continue. It seems we sometimes get back deleted emails which
      // should not derail the process.
    }
  }
  // Now do the fetch again getting the 'non campaign' (their jargon) mailings.
  // This is slightly explained at https://phabricator.wikimedia.org/T361621
  // The subset of mailings returned with no status passed in does not intersect with the
  // status when it is passed in - I opted to always get both types rather than use
  // a variable to choose which type - given we barely understand the difference.
  $mailerParameters['statuses'] = ['CAMPAIGN_ACTIVE', 'CAMPAIGN_COMPLETED', 'CAMPAIGN_CANCELLED'];
  $mailings = (array) $mailer->getMailings($mailerParameters)->getResponse();
  foreach ($mailings as $mailing) {
    /* @var \Omnimail\Silverpop\Responses\Mailing $mailing */
    try {
      $result = mapMailing($mailing) + ['is_multiple_report' => TRUE];
      if (!$params['is_include_text']) {
        unset($result['body_text'], $result['body_html']);
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
 * @param \Omnimail\Silverpop\Responses\Mailing $mailing
 *
 * @return array
 */
function mapMailing(\Omnimail\Silverpop\Responses\Mailing $mailing): array {
  $mailingKey = 'omnimail_mailing_' . $mailing->getMailingIdentifier();
  if (empty(\Civi::$statics[$mailingKey])) {
    \Civi::$statics[$mailingKey] = [
      'subject' => $mailing->getSubject(),
      'external_identifier' => $mailing->getMailingIdentifier(),
      'name' => substr($mailing->getName(), 0, 128),
      'body_html' => $mailing->getHtmlBody(),
      'body_text' => $mailing->getTextBody(),
      'tags' => $mailing->getTags(),
    ];
  }
  $result = \Civi::$statics[$mailingKey] + [
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
    'report_id' => $mailing->getReportID(),
  ];
  foreach ($result as $key => $value) {
    // Assuming we might change provider and they might not return
    // all the above, we unset any not returned.
    if ($value === NULL) {
      unset($result[$key]);
    }
  }
  return $result;
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
  $params['is_include_text'] = [
    'title' => ts('Include mailing text and html, set to FALSE for concise data'),
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => TRUE,
  ];
  $params['timeout'] = [
    'title' => ts('Http request time out'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 20,
  ];

}

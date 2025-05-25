<?php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Omnihell.get API
 *
 * See for https://phabricator.wikimedia.org/T230509 for background on this misery.
 *
 * We are mocking a browser login and scraping a webpage. Here be dragons. Stakeholders know
 * this.
 *
 * @param array $params
 *
 * @return array API result descriptor
 *
 * @throws \GuzzleHttp\Exception\GuzzleException
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_omnihell_get($params) {
  $client = CRM_Omnimail_Helper::getLoggedInBrowserSimulationClient($params);
  $response = $client->get('https://engage4.silverpop.com/lists.do', [
    'query' => [
      'action' => 'listSummary',
      'listId' => $params['list_id'],
    ],
  ]);

  $result = [];
  $elementWrapper = CRM_Omnimail_Helper::getValueFromResponseWithXPath($response, '//*[@id="newQueryEnglish"]');
  foreach ($elementWrapper  as $element) {
    $parentDom = $element->ownerDocument;
    $query = CRM_Utils_String::htmlToText($parentDom->saveHtml($element));
    $result[] = trim(str_replace("\n", ' ', $query));
  }

  // Figure out how to catch & handle exceptions - the above doesn't reach here.
  return civicrm_api3_create_success($result);
}

/**
 * Get details about Omnimails.
 *
 * @param $params
 */
function _civicrm_api3_omnihell_get_spec(&$params) {
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
  $params['list_id'] = [
    'title' => ts('ID of list to fetch'),
    'api.required' => TRUE,
  ];
}

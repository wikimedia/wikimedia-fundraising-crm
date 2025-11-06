<?php

use Omnimail\Silverpop\Credentials;
use GuzzleHttp\Client;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton@wikimedia.org
 * Date: 7/4/17
 * Time: 1:50 PM
 */
class CRM_Omnimail_Helper {

  /**
   * Array of CiviCRM settings for easy access.
   *
   * @var array
   */
  protected static $settings;
  /**
   * Get credentials
   *
   * @param $params
   * @return array
   */
  public static function getCredentials($params): array {
    $credentialKeys = ['username', 'password', 'client_id', 'client_secret', 'refresh_token', 'database_id'];
    if ((!isset($params['username']) || !isset($params['password']))
      // The latter 3 are required for Rest.
      && (!isset($params['client_id']) || !isset($params['client_secret']) || !isset($params['refresh_token']) )
    ) {
      $credentials = \Civi::settings()->get('omnimail_credentials');
      $credentials = $credentials[$params['mail_provider']] ?? [];
    }
    foreach ($credentialKeys as $credentialKey) {
      if (!empty($params[$credentialKey])) {
        $credentials[$credentialKey] = $params[$credentialKey];
      }
    }
    $mailerCredentials = array(
      'credentials' => new Credentials($credentials)
    );
    if (!empty($params['client'])) {
      $mailerCredentials['client'] = $params['client'];
    }
    return $mailerCredentials;
  }

  /**
   * Get settings.
   *
   * This is just a helper for convenience.
   *
   * We are not caching as there is caching in the api & we use this little
   * enough it's not worth extra caching.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getSettings() {
    $settings = civicrm_api3('Setting', 'get', array('group' => 'omnimail'));
    self::$settings= reset($settings['values']);
    return self::$settings;
  }

  /**
   * Get named setting.
   *
   * This is just a helper for convenience.
   *
   * @param string $name
   * @return mixed
   */
  public static function getSetting($name) {
    $settings = self::getSettings();
    return $settings[$name] ?? NULL;
  }

  /**
   * @param \Psr\Http\Message\ResponseInterface $res
   *
   * @param $xpathString
   *
   * @return DOMNodeList|false
   */
  public static function getValueFromResponseWithXPath(\Psr\Http\Message\ResponseInterface $response, $xpathString) {
    // The html coming back from silverpop is invalid & the issue is in the scripts
    // eg Unable to perform XPath operation. The entity "times" was referenced, but not declared.
    // So let's strip out all the scripts.
    $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', (string) $response->getBody());
    $doc = new DOMDocument();

    // The html is still rubbish but with this we can get to the end....
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    $xpath = new DOMXpath($doc);

    // We need these 2 values from the login page to post a login.
    // <input type="hidden" name="lt" value="LT-4949-eMKeYcJvWv59ml5FNYKiXMoaF1ESCW-cas"/>
    // <input type="hidden" name="execution" value="e1s1"/>
    return $xpath->query($xpathString);
  }

  /**
   * @param \Psr\Http\Message\ResponseInterface $response
   * @param $name
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function getInputValueFromResponseWithXpath(\Psr\Http\Message\ResponseInterface $response, $name) {
    $elementWrapper = CRM_Omnimail_Helper::getValueFromResponseWithXPath($response, '//input[@name="' . $name . '"]/@value');
    if ($elementWrapper->length > 0) {
      return $elementWrapper->item(0)->nodeValue;
    }
    else {
      throw new CRM_Core_Exception("Input node $name not found");
    }
  }

  /**
   * Get logged in guzzle client.
   *
   * @param $params
   *
   * @return \GuzzleHttp\Client
   * @throws \CRM_Core_Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function getLoggedInBrowserSimulationClient($params) {
    // We accept client as an input to support unit tests.
    $client = $params['client'] ?? new Client([
      'cookies' => true,
      'debug' => $params['debug'] ?? FALSE,
      'headers' => [
        // This is set in Trilogy sample code. Perhaps the reason is you have to
        // log in from a new device - this is from mine.
        'User-Agent' => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36",
      ]
    ]);

    $getResponse = $client->request('GET', 'https://login4.silverpop.com/login', []);

    $credentials = CRM_Omnimail_Helper::getCredentials($params)['credentials'];
    $response = $client->post('https://login4.silverpop.com/login', [
      'form_params' => [
        'password' => $credentials->get('password'),
        'username' => $credentials->get('username'),
        'lt' => CRM_Omnimail_Helper::getInputValueFromResponseWithXpath($getResponse, 'lt'),
        'execution' => CRM_Omnimail_Helper::getInputValueFromResponseWithXpath($getResponse, 'execution'),
        '_eventId' => 'submit',
      ],
      'allow_redirects' => FALSE,
    ]);
    if (strpos((string) $response->getBody(), 'loginForm')) {
      throw new CRM_Core_Exception('login failed');
    }
    return $client;
  }

}

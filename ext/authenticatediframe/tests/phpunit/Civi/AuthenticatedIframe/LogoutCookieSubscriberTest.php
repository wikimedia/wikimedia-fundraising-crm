<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class LogoutCookieSubscriberTest extends AuthenticatedIframeTestCase {

  public function testGetSubscribedEventsListensForStandaloneLogout(): void {
    $this->assertEquals(['civi.standalone.logout' => 'onLogout'], LogoutCookieSubscriber::getSubscribedEvents());
  }

  public function testCookiesToClearCoversEveryConfiguredRoute(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/iframe' => [],
      'civicrm/other-guarded-route' => [],
    ]]);
    $this->assertEquals([
      [LoginCookieSubscriber::cookieName('civicrm/iframe'), 'civicrm/iframe'],
      [LoginCookieSubscriber::cookieName('civicrm/other-guarded-route'), 'civicrm/other-guarded-route'],
    ], LogoutCookieSubscriber::cookiesToClear());
  }

  public function testNoConfiguredRoutesMeansNothingToClear(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => []]);
    $this->assertEquals([], LogoutCookieSubscriber::cookiesToClear());
  }

}

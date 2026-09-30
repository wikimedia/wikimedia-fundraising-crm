<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class LoginCookieSubscriberTest extends AuthenticatedIframeTestCase {

  public function testGetSubscribedEventsListensForStandaloneLoginSuccess(): void {
    $this->assertEquals(['civi.standalone.login' => 'onLogin'], LoginCookieSubscriber::getSubscribedEvents());
  }

  public function testRoutesToIssueForIncludesOnlyRoutesContactHasPermissionFor(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/permitted' => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
      'civicrm/not-permitted' => ['permissions' => 'access CiviMail', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);

    $this->assertEquals(['civicrm/permitted'], array_keys(LoginCookieSubscriber::routesToIssueFor($contactID)));
  }

  public function testRoutesToIssueForExcludesRouteWithNoPermissionsConfigured(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/no-permissions-set' => ['frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);

    $this->assertSame([], LoginCookieSubscriber::routesToIssueFor($contactID));
  }

}

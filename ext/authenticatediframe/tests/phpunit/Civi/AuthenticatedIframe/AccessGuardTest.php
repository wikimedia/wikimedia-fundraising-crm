<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class AccessGuardTest extends AuthenticatedIframeTestCase {

  const ROUTE = 'civicrm/iframe';

  public function tearDown(): void {
    unset($_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)]);
    parent::tearDown();
  }

  private function setCookie(int $contactID): void {
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
  }

  public function testUnconfiguredPathIsNotAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => []]);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testNoCookieIsNotAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com']]]);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testValidCookieAndRequiredPermissionIsAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com']]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $this->setCookie($contactID);
    $this->assertTrue(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testNoPermissionConfiguredIsNeverAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['frame_ancestors' => 'https://example.com']]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $this->setCookie($contactID);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testNoFrameAncestorsConfiguredIsNeverAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM']]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $this->setCookie($contactID);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testValidCookieButMissingPermissionIsNotAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com']]]);
    $this->setCookie($this->createFixtureUser());
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testArrayPermissionRequiresAll(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => ['access CiviCRM', 'access CiviMail'], 'frame_ancestors' => 'https://example.com']]]);

    $partialContactID = $this->createFixtureUser();
    $this->grantPermissions($partialContactID, ['access CiviCRM']);
    $this->setCookie($partialContactID);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));

    $fullContactID = $this->createFixtureUser();
    $this->grantPermissions($fullContactID, ['access CiviCRM', 'access CiviMail']);
    $this->setCookie($fullContactID);
    $this->assertTrue(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testSubrouteIsNotAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com']]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $this->setCookie($contactID);
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE . '/subpage'));
  }

  public function testCookieIssuedForAnotherRouteIsNotAuthenticated(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com']]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, 'civicrm/some-other-route');
    $this->assertFalse(AccessGuard::isAuthenticatedForPath(self::ROUTE));
  }

  public function testIsPastHalfLifeWithMoreThanHalfTtlRemaining(): void {
    $expiry = \CRM_Utils_Time::time() + 3600;
    $this->assertFalse(AccessGuard::isPastHalfLife($expiry, 3600));
  }

  public function testIsPastHalfLifeWithLessThanHalfTtlRemaining(): void {
    $expiry = \CRM_Utils_Time::time() + 1000;
    $this->assertTrue(AccessGuard::isPastHalfLife($expiry, 3600));
  }

}

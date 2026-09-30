<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * Invokes Page\ExampleFixture through CRM_Core_Invoke::runItem(), with a
 * civicrm_menu row stored per test — this exercises the same
 * access_callback + page-dispatch logic a real registered route goes
 * through.
 *
 * @group headless
 */
class RouteAuthTest extends AuthenticatedIframeTestCase {

  const ROUTE = 'civicrm/authenticatediframe/example';

  public function testMenuRendersPageWhenCookieAndPermissionValid(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $contents = $this->invokeMenuItem();

    $this->assertStringContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuEstablishesContactIdentityForAttribution(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $this->invokeMenuItem();

    $this->assertSame($contactID, \CRM_Core_Session::getLoggedInContactID());
    $this->assertSame(\CRM_Core_BAO_UFMatch::getUFId($contactID), $GLOBALS['loggedInUserId']);
    $this->assertSame($contactID, (int) \CRM_Core_DAO::singleValueQuery('SELECT @civicrm_user_id'));
  }

  public function testMenuDeniedWithoutCookie(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuShowsStorageAccessPromptForUnauthenticatedIframeLoad(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';

    $contents = $this->invokeMenuItem();

    $this->assertStringContainsString('requestStorageAccess', $contents);
    $this->assertStringNotContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuShowsLoginForUnauthenticatedIframeLoadWithLoginParam(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
    $_GET['authenticatediframe_login'] = '1';

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuDeniedWithoutRequiredPermission(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviMail']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuDeniedWithExpiredCookie(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() - 60, self::ROUTE);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuDeniedForDeactivatedUser(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser(isActive: FALSE);
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuDeniedWhenRouteNotConfigured(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/some-other-route' => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $contents = $this->invokeMenuItem();
    // Route not configured, don't serve anything except a 403
    $this->assertStringNotContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuRendersPageForCrossSiteIframeEmbed(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';

    $contents = $this->invokeMenuItem();

    $this->assertStringContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuRendersPageForSameOriginRequest(): void {
    // e.g. the page's own forms, posting back to this site from within the frame.
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

    $contents = $this->invokeMenuItem();

    $this->assertStringContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuDeniedForCrossSiteNonIframeRequest(): void {
    // e.g. an <img>/<script> pull, or a forged request direct to an URL, that
    // would still carry the iframe-auth cookie.
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'image';

    $contents = $this->invokeMenuItem();

    $this->assertStringNotContainsString('civicrm-authenticatediframe-ready', $contents);
  }

  public function testMenuDeniedForCrossSiteFormPostIntoIframe(): void {
    // e.g. <form method="post" target="hiddenIframe"> on an attacker's page:
    // Sec-Fetch-Dest is 'iframe' for this too, so Origin is what catches it.
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';

    $contents = $this->invokeMenuItem();

    $this->assertStringNotContainsString('authenticatediframe example page reached', $contents);
  }

  public function testMenuDeniedForPostWithoutOrigin(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $contents = $this->invokeMenuItem();

    $this->assertStringNotContainsString('authenticatediframe example page reached', $contents);
  }

  public function testMenuRendersPageForPostFromOwnOrigin(): void {
    // e.g. the framed page's own forms.
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = \CRM_Utils_System::baseURL();
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';

    $contents = $this->invokeMenuItem();

    $this->assertStringContainsString('authenticatediframe example page reached', $contents);
  }

  public function testMenuDeniedWithCookieIssuedForAnotherRoute(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, 'civicrm/some-other-route');

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('You do not have permission to access this page.');
    $this->invokeMenuItem();
  }

  public function testMenuDeniedWhenPageIsNotIframePage(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      self::ROUTE => ['permissions' => 'access CiviCRM', 'frame_ancestors' => 'https://example.com'],
    ]]);
    $contactID = $this->createFixtureUser();
    $this->grantPermissions($contactID, ['access CiviCRM']);
    $_COOKIE[LoginCookieSubscriber::cookieName(self::ROUTE)] = IframeCookieAuth::buildToken($contactID, \CRM_Utils_Time::time() + 3600, self::ROUTE);

    $contents = $this->invokeMenuItem(Page\PlainPageFixture::class);

    $this->assertStringNotContainsString('authenticatediframe plain page reached', $contents);
  }

  private function invokeMenuItem(string $pageCallback = Page\ExampleFixture::class): string {
    // AccessGuard looks up the route's page_callback, so it needs a real
    // civicrm_menu row, rolled back by TransactionalInterface.
    $menu = new \CRM_Core_DAO_Menu();
    $menu->domain_id = \CRM_Core_Config::domainID();
    $menu->path = self::ROUTE;
    $menu->title = 'Authenticated iframe example';
    $menu->page_callback = serialize($pageCallback);
    $menu->access_callback = serialize(['Civi\AuthenticatedIframe\AccessGuard', 'checkMenu']);
    $menu->access_arguments = serialize([]);
    $menu->save();
    return $this->invokeRoute(self::ROUTE);
  }

}

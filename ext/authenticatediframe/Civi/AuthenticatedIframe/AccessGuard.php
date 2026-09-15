<?php

namespace Civi\AuthenticatedIframe;

/**
 * Menu access_callback for a route configured in authenticatediframe_routes.
 * Returns TRUE only if the route has a valid iframe-auth cookie and
 * that cookie's contact holds the route's configured permissions.
 */
class AccessGuard {

  /**
   * Test seam: overridden in tests to avoid a real process exit.
   * @internal
   */
  public static $exitFn = ['CRM_Utils_System', 'civiExit'];

  public static function checkMenu(): bool {
    $requestPath = (string) \CRM_Utils_System::currentPath();

    if (!self::isRouteConfigured($requestPath) || !self::isIframePage($requestPath)) {
      // FrameAncestorsSubscriber has nothing to set for a route that isn't
      // (properly) configured, so rather than showing a login form, deny.
      self::denyAccess();
    }

    if (!self::isAllowedFetchContext() || !self::isAllowedOrigin()) {
      self::denyAccess();
    }

    if (!self::isAuthenticatedForPath($requestPath)) {
      // The prompt page reloads the page with this param once it knows cookies aren't blocked.
      if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? NULL) === 'iframe' && empty($_GET['authenticatediframe_login'])) {
        self::renderStorageAccessPrompt();
      }
      // The route is configured and we have Storage Access if in an iframe,
      // so show a login form if not authenticated.
      return FALSE;
    }

    self::establishRequestIdentity($requestPath);
    // Only refresh on POST after isAllowedOrigin() passes
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      self::refreshCookie($requestPath);
    }
    return TRUE;
  }

  private static function isRouteConfigured(string $requestPath): bool {
    $route = ConfiguredRoutes::get($requestPath);
    return $route !== NULL && $route['permissions'] && $route['frame_ancestors'];
  }

  /**
   * Only allow if the page extends IframePage.
   */
  private static function isIframePage(string $requestPath): bool {
    return is_a(\CRM_Core_Menu::get($requestPath)['page_callback'], IframePage::class, TRUE);
  }

  /**
   * Sec-Fetch-Site/-Dest headers distinguish a genuine cross-site iframe
   * embed from other cross-site requests that would also carry the
   * iframe-auth cookie (e.g. an <img>).
   * Fails open when the headers are absent (Mar 2023 or older Safari);
   * IframePage only doing work on POSTs is the fallback there.
   */
  private static function isAllowedFetchContext(): bool {
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? NULL;
    if ($site !== 'cross-site') {
      return TRUE;
    }
    return ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? NULL) === 'iframe';
  }

  /**
   * A cross-site form POST targeted at an iframe passes
   * isAllowedFetchContext() as Sec-Fetch-Dest is 'iframe' for it too, so
   * non-GET/HEAD requests additionally require the Origin header to be our
   * own origin.
   * Browsers always send Origin on a POST, so unlike Sec-Fetch-* this also
   * covers browsers without fetch metadata (older Safari).
   */
  private static function isAllowedOrigin(): bool {
    // Fallback to GET is just for tests
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], TRUE)) {
      return TRUE;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? NULL;
    return $origin !== NULL && self::normalizeOrigin($origin) === self::normalizeOrigin(\CRM_Utils_System::baseURL());
  }

  private static function normalizeOrigin(string $url): string {
    $parts = parse_url($url);
    $origin = strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? ''));
    return isset($parts['port']) ? "$origin:{$parts['port']}" : $origin;
  }

  /**
   * Rejects with a 403 instead of returning FALSE and letting core
   * show a login page.
   */
  private static function denyAccess(): void {
    http_response_code(403);
    \CRM_Utils_System::setHttpHeader('Content-Security-Policy', "frame-ancestors 'none'");
    (self::$exitFn)();
  }

  /**
   * Browsers blocking third-party cookies by default (Safari, Firefox)
   * won't send the iframe-auth cookie until the frame is granted storage
   * access, so we ask for it.
   */
  private static function renderStorageAccessPrompt(): void {
    echo \CRM_Core_Smarty::singleton()->fetchWith('CRM/AuthenticatedIframe/StorageAccessPrompt.tpl', [
      'loginUrl' => \CRM_Utils_System::url('civicrm/login', NULL, TRUE),
    ]);
    (self::$exitFn)();
  }

  /**
   * Use a fake session for the user so anything the page writes is
   * attributed to them instead of running as anonymous.
   * Same as authx uses for stateless (e.g. Bearer-token) auth.
   */
  private static function establishRequestIdentity(string $requestPath): void {
    $contactID = IframeCookieAuth::validate(LoginCookieSubscriber::cookieName($requestPath), $requestPath);
    $userID = \CRM_Core_BAO_UFMatch::getUFId($contactID);

    \CRM_Core_Session::useFakeSession();
    $session = \CRM_Core_Session::singleton();
    $session->set('ufID', $userID);
    $session->set('userID', $contactID);
    (new \Civi\Authx\Standalone())->loginStateless($userID);
    \CRM_Core_DAO::executeQuery('SET @civicrm_user_id = %1', [1 => [$contactID, 'Integer']]);
  }

  private static function refreshCookie(string $requestPath): void {
    $route = ConfiguredRoutes::get($requestPath);
    $cookieName = LoginCookieSubscriber::cookieName($requestPath);
    $claims = IframeCookieAuth::getClaims($cookieName, $requestPath);
    if ($claims === NULL || !self::isPastHalfLife($claims['exp'], $route['ttl_seconds'])) {
      return;
    }
    IframeCookieAuth::issue($cookieName, $requestPath, $claims['contactId'], $route['ttl_seconds']);
  }

  /**
   * Only reissue once the cookie is more than halfway to expiry.
   */
  public static function isPastHalfLife(int $expiry, int $ttlSeconds): bool {
    return ($expiry - \CRM_Utils_Time::time()) < ($ttlSeconds / 2);
  }

  /**
   * Is the user authenticated for this path?
   */
  public static function isAuthenticatedForPath(string $requestPath): bool {
    $route = ConfiguredRoutes::get($requestPath);
    if ($route === NULL || !$route['permissions'] || !$route['frame_ancestors']) {
      return FALSE;
    }
    $contactID = IframeCookieAuth::validate(LoginCookieSubscriber::cookieName($requestPath), $requestPath);
    if ($contactID === NULL || !self::isActiveUser($contactID)) {
      return FALSE;
    }
    return \CRM_Core_Permission::check($route['permissions'], $contactID);
  }

  /**
   * Check that the user is still active.
   * TODO: Standalone core's own permission check doesn't do this, maybe we
   * can drop this or mirror the core approach once done.
   */
  private static function isActiveUser(int $contactID): bool {
    return (bool) \Civi\Api4\User::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_active', '=', TRUE)
      ->selectRowCount()
      ->execute()->count();
  }

}

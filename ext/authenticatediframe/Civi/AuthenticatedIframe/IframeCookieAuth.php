<?php

namespace Civi\AuthenticatedIframe;

/**
 * Issues and validates a signed, path-scoped, SameSite=None cookie that
 * carries a contact's identity for a single allowlisted route.
 */
class IframeCookieAuth {

  const SCOPE = 'authenticatediframe';

  /**
   * Set the cookie on the current response, scoped to $route, identifying
   * $contactID, for $ttlSeconds.
   */
  public static function issue(string $cookieName, string $route, int $contactID, int $ttlSeconds): void {
    $expires = \CRM_Utils_Time::time() + $ttlSeconds;
    $path = '/' . ltrim($route, '/');
    setcookie($cookieName, self::buildToken($contactID, $expires, $route), self::buildCookieOptions($path, $expires));
  }

  /**
   * Split out from issue() for testing.
   */
  public static function buildToken(int $contactID, int $expires, string $route): string {
    return \Civi::service('crypto.jwt')->encode([
      'scope' => self::SCOPE,
      'contactId' => $contactID,
      'route' => $route,
      'exp' => $expires,
    ]);
  }

  /**
   * Expire the cookie on the current response. Must be called with the
   * same $route it was issued with.
   */
  public static function clear(string $cookieName, string $route): void {
    $path = '/' . ltrim($route, '/');
    setcookie($cookieName, '', self::buildCookieOptions($path, \CRM_Utils_Time::time() - 1));
  }

  /**
   * Split out from issue() for testing.
   */
  public static function buildCookieOptions(string $path, int $expires): array {
    return [
      'expires' => $expires,
      'path' => $path,
      // Required by SameSite=None anyway
      'secure' => TRUE,
      'httponly' => TRUE,
      'samesite' => 'None',
    ];
  }

  /**
   * @return int|null
   *   The authenticated contact ID, or NULL if the cookie is missing,
   *   expired, issued for a different route, or otherwise invalid.
   */
  public static function validate(string $cookieName, string $route): ?int {
    $claims = self::getClaims($cookieName, $route);
    return $claims === NULL ? NULL : $claims['contactId'];
  }

  /**
   * @return array{contactId: int, route: string, exp: int}|null
   *   The cookie's claims, or NULL if it's missing, expired, issued for a
   *   different route, or otherwise invalid.
   */
  public static function getClaims(string $cookieName, string $route): ?array {
    $token = $_COOKIE[$cookieName] ?? NULL;
    if (!$token) {
      return NULL;
    }
    try {
      $claims = \Civi::service('crypto.jwt')->decode($token);
    }
    catch (\Throwable $e) {
      return NULL;
    }
    if (($claims['scope'] ?? NULL) !== self::SCOPE || empty($claims['contactId']) || ($claims['route'] ?? NULL) !== $route) {
      return NULL;
    }
    return $claims;
  }

}

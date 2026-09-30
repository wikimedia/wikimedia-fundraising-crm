<?php

namespace Civi\AuthenticatedIframe;

use Civi\Core\Service\AutoService;
use Civi\Crypto\Exception\CryptoException;
use Civi\Standalone\Event\LoginEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * On successful login, issues one path-scoped iframe-auth cookie per route
 * configured in the authenticatediframe_routes setting that the logging-in
 * contact currently holds the permissions for.
 *
 * @service authenticatedIframe.loginCookieSubscriber
 */
class LoginCookieSubscriber extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return ['civi.standalone.login' => 'onLogin'];
  }

  public function onLogin(LoginEvent $event): void {
    if ($event->stage !== 'login_success') {
      return;
    }
    $contactID = \CRM_Core_Session::singleton()->get('userID');
    if (!$contactID) {
      return;
    }
    try {
      foreach (self::routesToIssueFor($contactID) as $path => $config) {
        IframeCookieAuth::issue(self::cookieName($path), $path, $contactID, $config['ttl_seconds']);
      }
    }
    catch (CryptoException $e) {
      // e.g. no signing key configured - don't break login in general.
      \Civi::log()->warning('authenticatediframe: failed to issue iframe-auth cookie: ' . $e->getMessage());
    }
  }

  /**
   * Configured routes the contact currently holds the permissions for.
   *
   * @return array<string, array{frame_ancestors: string|null, permissions: string|array|null, ttl_seconds: int}>
   */
  public static function routesToIssueFor(int $contactID): array {
    $routes = [];
    foreach (ConfiguredRoutes::getAll() as $path => $config) {
      if ($config['permissions'] && \CRM_Core_Permission::check($config['permissions'], $contactID)) {
        $routes[$path] = $config;
      }
    }
    return $routes;
  }

  public static function cookieName(string $path): string {
    return 'civiIframeAuth_' . substr(md5($path), 0, 12);
  }

}

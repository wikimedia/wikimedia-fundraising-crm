<?php

namespace Civi\AuthenticatedIframe;

use Civi\Core\Service\AutoService;
use Civi\Standalone\Event\LogoutEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears every issued iframe-auth cookie whenever a user logs out.
 *
 * @service authenticatedIframe.logoutCookieSubscriber
 */
class LogoutCookieSubscriber extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return ['civi.standalone.logout' => 'onLogout'];
  }

  public function onLogout(LogoutEvent $event): void {
    foreach (self::cookiesToClear() as [$cookieName, $route]) {
      IframeCookieAuth::clear($cookieName, $route);
    }
  }

  /**
   * @return array<array{0: string, 1: string}>
   *   [cookieName, route] to clear, split out from onLogout() for testing.
   */
  public static function cookiesToClear(): array {
    $toClear = [];
    foreach (array_keys(ConfiguredRoutes::getAll()) as $route) {
      $toClear[] = [LoginCookieSubscriber::cookieName($route), $route];
    }
    return $toClear;
  }

}

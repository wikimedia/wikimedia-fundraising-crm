<?php

namespace Civi\AuthenticatedIframe;

use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets the frame-ancestors CSP directive for any route configured in
 * authenticatediframe_routes.
 *
 * @service authenticatedIframe.frameAncestorsSubscriber
 */
class FrameAncestorsSubscriber extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    // Belt & braces: this still falls back to 'none'
    // for the header itself when frame_ancestors isn't set,
    // so the denial isn't framed, because civi.invoke.auth
    // fires before core checks the access callback.
    return ['&civi.invoke.auth' => 'onInvoke'];
  }

  public function onInvoke(array $path): void {
    $value = self::getHeaderValue($path);
    if ($value !== NULL) {
      \CRM_Utils_System::setHttpHeader('Content-Security-Policy', 'frame-ancestors ' . $value);
    }
  }

  /**
   * Split out from onInvoke() for testing.
   */
  public static function getHeaderValue(array $path): ?string {
    $route = ConfiguredRoutes::get(implode('/', $path));
    return $route === NULL ? NULL : ($route['frame_ancestors'] ?? "'none'");
  }

}

<?php

namespace Civi\AuthenticatedIframe;

/**
 * Base for pages to be shown in iframes; AccessGuard only allows routes for
 * pages that extend this.
 *
 * GETs only render a handshake that gets the desired params from the parent
 * via postMessage and re-submits them as a same-origin POST, so a
 * cross-site page can't time a GET.
 */
abstract class IframePage extends \CRM_Core_Page {

  /**
   * Test seam: overridden in tests to avoid a real process exit.
   * @internal
   */
  public static $exitFn = ['CRM_Utils_System', 'civiExit'];

  /**
   * Names of the params the parent sends via postMessage.
   */
  abstract protected function getHandshakeParams(): array;

  /**
   * @return string
   *   The page's HTML.
   */
  abstract protected function handlePost(): string;

  final public function run() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
      // Add the hidden fields to be filled and then posted
      echo \CRM_Core_Smarty::singleton()->fetchWith('CRM/AuthenticatedIframe/Handshake.tpl', [
        'handshakeParams' => array_fill_keys($this->getHandshakeParams(), ''),
      ]);
    }
    else {
      $params = [];
      foreach ($this->getHandshakeParams() as $name) {
        $params[$name] = $this->getPostParam($name, 'String');
      }
      // Assign to HandshakeParams.tpl (included in implementing tpls)
      // to output as hidden form fields to be resent, if required
      $this->assign('handshakeParams', $params);
      echo $this->handlePost();
    }
    (self::$exitFn)();
  }

  final protected function getPostParam(string $name, string $type) {
    return \CRM_Utils_Request::retrieve($name, $type, NULL, FALSE, NULL, 'POST');
  }

}

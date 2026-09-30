<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class FrameAncestorsSubscriberTest extends AuthenticatedIframeTestCase {

  public function testGetSubscribedEventsListensForInvokeAuth(): void {
    $this->assertEquals(['&civi.invoke.auth' => 'onInvoke'], FrameAncestorsSubscriber::getSubscribedEvents());
  }

  public function testReturnsConfiguredValueForKnownRoute(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/donoriframe' => ['frame_ancestors' => 'https://example.com'],
    ]]);
    $this->assertEquals('https://example.com', FrameAncestorsSubscriber::getHeaderValue(['civicrm', 'donoriframe']));
  }

  public function testReturnsNullForUnconfiguredRoute(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/donoriframe' => ['frame_ancestors' => 'https://example.com'],
    ]]);
    $this->assertNull(FrameAncestorsSubscriber::getHeaderValue(['civicrm', 'contact', 'view']));
  }

  public function testDefaultsToNoneWhenRouteOmitsFrameAncestors(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/iframe' => [],
    ]]);
    $this->assertEquals("'none'", FrameAncestorsSubscriber::getHeaderValue(['civicrm', 'iframe']));
  }

  public function testReturnsNullForSubroute(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/iframe' => ['frame_ancestors' => 'https://example.com'],
    ]]);
    $this->assertNull(FrameAncestorsSubscriber::getHeaderValue(['civicrm', 'iframe', 'page1']));
  }

}

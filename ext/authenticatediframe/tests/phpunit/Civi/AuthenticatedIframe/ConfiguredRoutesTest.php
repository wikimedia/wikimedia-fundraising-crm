<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class ConfiguredRoutesTest extends AuthenticatedIframeTestCase {

  public function testDefaultsTtlToStandaloneSessionLifetimeAndLeavesFrameAncestorsAndPermissionsUnset(): void {
    // loadMandatory() replaces the whole mandatory array each call, so both
    // settings need to go in one call — a second call would silently wipe
    // out the first.
    \Civi::settings()->loadMandatory([
      'standaloneusers_session_max_lifetime' => 90,
      'authenticatediframe_routes' => ['civicrm/donoriframe' => []],
    ]);
    $this->assertEquals(['frame_ancestors' => NULL, 'permissions' => NULL, 'ttl_seconds' => 5400], ConfiguredRoutes::get('civicrm/donoriframe'));
  }

  public function testExplicitTtlAndFrameAncestorsOverrideDefaults(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/donoriframe' => ['ttl_seconds' => 60, 'frame_ancestors' => 'https://example.com', 'permissions' => 'access CiviCRM'],
    ]]);
    $this->assertEquals(['frame_ancestors' => 'https://example.com', 'permissions' => 'access CiviCRM', 'ttl_seconds' => 60], ConfiguredRoutes::get('civicrm/donoriframe'));
  }

  public function testUnconfiguredRouteReturnsNull(): void {
    \Civi::settings()->loadMandatory(['authenticatediframe_routes' => ['civicrm/donoriframe' => []]]);
    $this->assertNull(ConfiguredRoutes::get('civicrm/somewhere-else'));
  }

}

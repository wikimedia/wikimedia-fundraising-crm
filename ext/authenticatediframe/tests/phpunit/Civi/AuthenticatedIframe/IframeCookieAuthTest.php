<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class IframeCookieAuthTest extends AuthenticatedIframeTestCase {

  const COOKIE_NAME = 'civiIframeAuthTest';
  const ROUTE = 'civicrm/iframe';

  public function tearDown(): void {
    unset($_COOKIE[self::COOKIE_NAME]);
    parent::tearDown();
  }

  public function testBuildCookieOptionsScopesToPathAndSameSiteNone(): void {
    $options = IframeCookieAuth::buildCookieOptions('/civicrm/iframe', 12345);
    $this->assertEquals('/civicrm/iframe', $options['path']);
    $this->assertEquals('None', $options['samesite']);
    $this->assertEquals(12345, $options['expires']);
    $this->assertTrue($options['httponly']);
    $this->assertTrue($options['secure']);
  }

  public function testValidateRejectsMissingCookieThenAcceptsTokenItIssued(): void {
    $this->assertNull(IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
    $_COOKIE[self::COOKIE_NAME] = IframeCookieAuth::buildToken(456, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    $this->assertEquals(456, IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
  }

  public function testValidateRejectsWrongScope(): void {
    $_COOKIE[self::COOKIE_NAME] = \Civi::service('crypto.jwt')->encode([
      'scope' => 'some-other-scope',
      'contactId' => 456,
      'route' => self::ROUTE,
      'exp' => \CRM_Utils_Time::time() + 3600,
    ]);
    $this->assertNull(IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
  }

  public function testValidateRejectsWrongRoute(): void {
    $_COOKIE[self::COOKIE_NAME] = IframeCookieAuth::buildToken(456, \CRM_Utils_Time::time() + 3600, 'civicrm/some-other-route');
    $this->assertNull(IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
  }

  public function testValidateRejectsExpiredToken(): void {
    $_COOKIE[self::COOKIE_NAME] = \Civi::service('crypto.jwt')->encode([
      'scope' => IframeCookieAuth::SCOPE,
      'contactId' => 456,
      'route' => self::ROUTE,
      'exp' => \CRM_Utils_Time::time() - 60,
    ]);
    $this->assertNull(IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
  }

  public function testGetClaims(): void {
    $expires = \CRM_Utils_Time::time() + 3600;
    $_COOKIE[self::COOKIE_NAME] = IframeCookieAuth::buildToken(456, $expires, self::ROUTE);
    $claims = IframeCookieAuth::getClaims(self::COOKIE_NAME, self::ROUTE);
    $this->assertSame([
      'scope' => IframeCookieAuth::SCOPE,
      'contactId' => 456,
      'route' => self::ROUTE,
      'exp' => $expires,
    ], $claims);
  }

  public function testValidateRejectsTamperedToken(): void {
    $token = IframeCookieAuth::buildToken(456, \CRM_Utils_Time::time() + 3600, self::ROUTE);
    [$header, $payload, $signature] = explode('.', $token);
    $mid = (int) (strlen($payload) / 2);
    $flipped = $payload[$mid] === 'a' ? 'b' : 'a';
    $tamperedPayload = substr_replace($payload, $flipped, $mid, 1);
    $_COOKIE[self::COOKIE_NAME] = implode('.', [$header, $tamperedPayload, $signature]);
    $this->assertNull(IframeCookieAuth::validate(self::COOKIE_NAME, self::ROUTE));
  }

}

<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * @group headless
 */
class IframePageTest extends AuthenticatedIframeTestCase {

  public function tearDown(): void {
    unset($_SERVER['REQUEST_METHOD'], $_GET['example'], $_REQUEST['example'], $_POST['example']);
    parent::tearDown();
  }

  public function testGetRendersHandshakeOnly(): void {
    $_GET['example'] = $_REQUEST['example'] = 'from-query';

    $contents = $this->runPage(new Page\ExampleFixture());

    $this->assertStringContainsString('civicrm-authenticatediframe-ready', $contents);
    $this->assertStringContainsString('<input type="hidden" name="example" value="">', $contents);
    $this->assertStringNotContainsString('authenticatediframe example page reached', $contents);
  }

  public function testPostHandlesPageWithPostedParams(): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['example'] = 'from-post';
    $_GET['example'] = 'from-query';
    $page = new Page\ExampleFixture();

    $contents = $this->runPage($page);

    $this->assertStringContainsString('authenticatediframe example page reached', $contents);
    $this->assertSame(['example' => 'from-post'], $page->getTemplateVars('handshakeParams'));
  }

  private function runPage(IframePage $page): string {
    IframePage::$exitFn = function (): void {
      throw new \CRM_Core_Exception_PrematureExitException('IframePage exitFn called (test)', []);
    };
    ob_start();
    try {
      $page->run();
    }
    catch (\CRM_Core_Exception_PrematureExitException $e) {
      // Expected.
    }
    finally {
      $output = ob_get_clean();
      IframePage::$exitFn = ['CRM_Utils_System', 'civiExit'];
    }
    return $output;
  }

}

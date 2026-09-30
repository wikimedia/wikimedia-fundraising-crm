<?php
declare(strict_types = 1);
namespace Civi\AuthenticatedIframe;

/**
 * For tests that go through AccessGuard, including other extensions' tests
 * of their IframePage routes.
 */
trait AuthenticatedIframeTestTrait {

  /**
   * Create a real contact and user
   *
   * AccessGuard checks if the user is_active live on every request rather
   * than trusting the cookie alone.
   */
  protected function createFixtureUser(bool $isActive = TRUE): int {
    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('display_name', 'Iframe Test User')
      ->execute()->first()['id'];

    \Civi\Api4\User::create(FALSE)
      ->addValue('contact_id', $contactID)
      ->addValue('username', 'iframetestuser' . $contactID)
      ->addValue('uf_name', "iframetestuser$contactID@example.org")
      ->addValue('is_active', $isActive)
      ->execute();

    return $contactID;
  }

  /**
   * Grant the contact's user a role with these permissions.
   */
  protected function grantPermissions(int $contactID, array $permissions): void {
    $roleName = 'iframe_test_role_' . $contactID;
    $role = \Civi\Api4\Role::get(FALSE)->addWhere('name', '=', $roleName)->execute()->first();
    if ($role) {
      \Civi\Api4\Role::update(FALSE)->addValue('permissions', $permissions)->addWhere('id', '=', $role['id'])->execute();
    }
    else {
      \Civi\Api4\Role::create(FALSE)->addValue('name', $roleName)->addValue('label', $roleName)->addValue('permissions', $permissions)->execute();
      \Civi\Api4\User::update(FALSE)->addValue('roles:name', [$roleName])->addWhere('contact_id', '=', $contactID)->execute();
    }
  }

  /**
   * Run the route's menu item, through AccessGuard, and return its output.
   */
  protected function invokeRoute(string $route): string {
    $_SERVER['REQUEST_URI'] = '/' . $route;
    $_GET['q'] = $route;
    // PHPUnit has already written to stdout, so PHP treats headers as sent and
    // setcookie()/http_response_code() in AccessGuard cause warnings, suppress these
    set_error_handler(function (int $severity, string $message): bool {
      return $severity === E_WARNING && str_contains($message, 'headers already sent');
    });
    // AccessGuard calls civiExit() after denying or rendering the prompt, and
    // IframePage after rendering, which would kill the whole test process;
    // make it throw instead so it can be caught below.
    AccessGuard::$exitFn = IframePage::$exitFn = function (): void {
      throw new \CRM_Core_Exception_PrematureExitException('exitFn called (test)', []);
    };
    // When access_callback returns FALSE, CRM_Core_Invoke::runItem() calls
    // CRM_Utils_System::permissionDenied(), which under Standalone renders a
    // real login page and falls through to a real civiExit() - not catchable.
    // Throw instead.
    $config = \CRM_Core_Config::singleton();
    $originalUserSystem = $config->userSystem;
    $config->userSystem = new class extends \CRM_Utils_System_Standalone {
      public function permissionDenied() {
        throw new \CRM_Core_Exception(ts('You do not have permission to access this page.'));
      }
    };
    ob_start();
    try {
      \CRM_Core_Invoke::runItem(\CRM_Core_Menu::get($route));
    }
    catch (\CRM_Core_Exception_PrematureExitException $e) {
      // Expected: AccessGuard or IframePage called the stubbed exitFn above.
    }
    catch (\Throwable $e) {
      // Any other exception (e.g. permissionDenied()'s CRM_Core_Exception)
      // still needs the buffer closed before it propagates to the caller.
      ob_end_clean();
      throw $e;
    }
    finally {
      restore_error_handler();
      AccessGuard::$exitFn = IframePage::$exitFn = ['CRM_Utils_System', 'civiExit'];
      $config->userSystem = $originalUserSystem;
    }
    return ob_get_clean();
  }

  /**
   * Undo loadMandatory() settings, the fake session and request globals.
   */
  protected function resetIframeAuth(): void {
    \Civi::service('settings_manager')->useMandatory();
    $sessionSingleton = new \ReflectionProperty(\CRM_Core_Session::class, '_singleton');
    $sessionSingleton->setAccessible(TRUE);
    $sessionSingleton->setValue(NULL, NULL);
    $GLOBALS['loggedInUserId'] = NULL;
    \CRM_Core_DAO::executeQuery('SET @civicrm_user_id = NULL');
    $_COOKIE = [];
    unset(
      $_SERVER['REQUEST_URI'], $_GET['q'], $_GET['authenticatediframe_login'],
      $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_SEC_FETCH_DEST'],
      $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ORIGIN']
    );
  }

}

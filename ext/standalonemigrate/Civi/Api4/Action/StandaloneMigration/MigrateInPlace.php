<?php

namespace Civi\Api4\Action\StandaloneMigration;

use CRM_Standalonemigrate_ExtensionUtil as E;
use Civi\Api4\Generic\Result;

/**
 * This modifies the live database to make it Standalone compatible.
 *
 * It might break your site.
 *
 */
class MigrateInPlace extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @var bool
   *
   * Check that user is sure. This may well break the current site.
   */
  protected bool $areYouSure = FALSE;

  /**
   * @var bool
   *
   * Standard behaviour is to migrate users from the CMS database
   *
   * Alternatively we can wipe all user data and replace with a single
   * stock admin user
   */
  protected bool $wipeUsers = FALSE;

  /**
   * @var string
   *
   * The CMS user framework to migrate users from. By default will automatically
   * use the UF the extension is running under.
   *
   * (untested) this could be used to run the migration on the Target Site, by specificying
   * which CMS the user data has come from. Source UF should be in the same format as
   * CIVICRM_UF ( "Drupal", "Drupal8", "WordPress", "Backdrop", "Joomla" )
   */
  protected string $sourceUf = 'auto';

  private array $logMessages = [];

  /**
   * Run the migration
   *
   * @param \Civi\Api4\Generic\Result $result
   */
  public function _run(Result $result) {
    if (!$this->areYouSure) {
      $result[] = 'If you are 100% please tick the Are You Sure box and run again';
      return;
    }

    $this->log("Beginning in place standalonification");

    // we need some classes from Standaloneusers extension (even though it wont be installed
    // on the Source Site)
    $this->loadStandaloneusersClasses();

    // prep the SQL to run first before we start making any changes
    $migrationSql = $this->generateMigrationScript();

    // load the Standaloneusers delegate upgrader class in order to run
    // non-schema install steps
    // NOTE: this only works because these functions dont currently depend on anything in
    // the extension db schema - which is a bit of a fluke. this migration method
    // would become more complicated if this changes
    $delegateInstaller = new \CRM_Standaloneusers_Upgrader();

    $delegateInstaller->postInstall();
    $delegateInstaller->enable();

    // now run the generated script
    \CRM_Utils_File::runSqlQuery(CIVICRM_DSN, $migrationSql);

    $this->log("Migration completed. Your database should now be ready to use with Standalone");

    $result['log'] = $this->logMessages;
  }

  protected function log(string $message): void {
    \Civi::log('standalonemigrate')->info("Standalonemigrate: Migrate In Place: {$message}");
    $this->logMessages[] = $message;
  }

  /**
   * Standaloneusers wont be installed on the source site so we need to load classes from it manually
   */
  protected function loadStandaloneusersClasses() {
    require_once \Civi::paths()->getPath("[civicrm.root]/ext/standaloneusers/CRM/Standaloneusers/Upgrader.php");
    if ($this->wipeUsers) {
      // required for hashing new admin pass
      require_once \Civi::paths()->getPath("[civicrm.root]/ext/standaloneusers/Civi/Standalone/PasswordAlgorithms/AlgorithmInterface.php");
      require_once \Civi::paths()->getPath("[civicrm.root]/ext/standaloneusers/Civi/Standalone/PasswordAlgorithms/Drupal7.php");
    }
  }

  protected function generateMigrationScript(): string {
    $sqls = [];

    // this is needed for e.g. serialized role permissions
    $valueSep = \CRM_Core_DAO::VALUE_SEPARATOR;
    $sqls[] = "SET @VALUE_SEP = '{$valueSep}';";

    // always use the core migration file
    $sqls[] = file_get_contents(E::path('sql/migrate_in_place.sql'));

    if ($this->wipeUsers) {
      // we will link the new admin user to the current contact

      $adminContact = \CRM_Core_Session::getLoggedInContactID();
      $sqls[] = "SET @ADMIN_CONTACT_ID = {$adminContact};";

      // we generate and hash a new admin password. this is logged for the user to pick up
      $adminPass = \CRM_Utils_String::createRandom(10, \CRM_Utils_String::ALPHANUMERIC);

      // @see \Civi\Standalone\Security::hashPassword
      // note: even if that becomes configurable, hashing is unlikely to be
      // configured on the source site, so let's just stick with Drupal7 hash
      $algo = new \Civi\Standalone\PasswordAlgorithms\Drupal7();
      $hashedPass = $algo->hashPassword($adminPass);

      $sqls[] = "SET @HASHED_ADMIN_PASS = '{$hashedPass}';";
      $sqls[] = file_get_contents(E::path('sql/migrate_in_place_wipe_users.sql'));

      $this->log("Wiping users. Admin will be created with temporary password: {$adminPass}");
    }
    else {
      $sqls[] = $this->getUfSql();
    }

    return implode("\n", $sqls);
  }

  protected function getUfSql(): string {
    $source = ($this->sourceUf === 'auto') ? CIVICRM_UF : $this->sourceUf;

    switch ($source) {
      case 'Drupal':
      // TO CHECK: backdrop user schema matches D7?
      // case 'Backdrop':
        return file_get_contents(E::path('sql/migrate_in_place_d7.sql'));

//      case 'Drupal8':
//        return file_get_contents('sql/migrate_in_place_d8.sql');

//      case 'WordPress':
//        return file_get_contents('sql/migrate_in_place_wp.sql');

//        return file_get_contents('sql/migrate_in_place_bd.sql');

    }

    throw new \CRM_Core_Exception("User migration is not implemented yet for {$source}. You could use wipeUsers to reset users to a single admin");
  }

}

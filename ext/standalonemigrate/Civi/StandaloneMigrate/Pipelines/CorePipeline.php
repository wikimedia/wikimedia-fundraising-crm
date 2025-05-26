<?php

namespace Civi\StandaloneMigrate\Pipelines;

use Civi\StandaloneMigrate\SourceUf\SourceUf;

abstract class CorePipeline {

  protected string $migrationId;
  protected string $dumpFilePath;

  protected string $sourceDirectory;
  protected bool $skipCheckingTargetEmpty = FALSE;
  protected bool $transferData = TRUE;
  protected bool $transferUsers = FALSE;
  protected bool $transferFiles = FALSE;

  protected SourceUf $sourceUf;

  protected array $output = [];

  /**
   * @param array $params
   */
  public function __construct(array $params) {
    $this->migrationId = $params['migration_id'] ?? FALSE;
    $this->dumpFilePath = $params['dump_file_path'] ?? \CRM_Utils_File::addTrailingSlash(\CRM_Core_Config::singleton()->uploadDir) . "standalonemigrate_{$this->migrationId}.sql";

    $this->transferData = $params['transfer_data'] ?? FALSE;
    $this->transferUsers = $params['transfer_users'] ?? FALSE;
    $this->transferFiles = $params['transfer_files'] ?? FALSE;

    $this->sourceDirectory = $params['source_directory'] ?? '';

    $this->skipCheckingTargetEmpty = $params['skip_checking_target_empty'] ?? FALSE;
  }

  /**
   * Check requirements
   *
   * @return array any errors
   */
  public function checkRequirements(): array {
    $errors = [];

    if (!$this->migrationId) {
      $errors[] = 'Missing Migration ID';
    }

    try {
      $this->execOrThrow("mysqldump --version");
    }
    catch (\Exception $e) {
      $errors[] = 'Error attempting to run mysqldump on the server: ' . $e->getMessage();
    }

    return $errors;
  }

  /**
   * Run the migration
   *
   * @return array result array
   */
  public function run(): array {
    # TODO: we would like to run a clean install on the target site here so you dont
    # have to do it manually first. but `cv core:install` seems to have problems
    # (maybe some of the env vars from the `cv api4 StandaloneMigration.run` call
    # are cascading and interferring?)
    #
    # $this->targetSiteCleanInstall();

    // check target site
    // TODO: should this be in checkRequirements? maybe

    $this->info("Assuring the target site is Standalone...");
    $this->assureTargetSiteIsStandalone();
    $this->info("Assuring the target site is Standalone... success!");

    if ($this->skipCheckingTargetEmpty ?? FALSE) {
      $this->warning('Skipping checks that target site is empty - any data on the target site may be lost!');
    }
    else {
      $this->info("Assuring the target site is empty...");
      $this->assureTargetSiteIsEmpty();
      $this->info("Assuring the target site is empty... success!");
    }

    // we need to get this before the bulk transfer overwrites civicrm_extension
    $standaloneusersSchemaVersion = $this->getTargetStandaloneusersVersion();

    if ($this->transferData) {
      // transfer the bulk of site data
      $this->doBulkTransfer();
    }

    // update required settings
    $this->info("Purging standalonemigrate from target site DB...");
    $this->purgeStandaloneMigrate();
    $this->info("Purging standalonemigrate from target site DB... success!");

    $this->info("Ensuring standaloneusers extension is enabled...");
    $this->enableStandaloneusersExtension($standaloneusersSchemaVersion);
    $this->info("Ensuring standaloneusers extension is enabled... success!");

    $this->info("Ensuring Authx Password Cred is enabled...");
    $this->enableAuthxPasswordCred();
    $this->info("Ensuring Authx Password Cred is enabled... success!");

    // transfer users

    if ($this->transferUsers) {
      $this->sourceUf = SourceUf::get($this->sourceDirectory);

      $this->info("Clearing users from target site...");
      $this->clearUsersAndRoles();
      $this->info("Clearing users from target site... success!");

      $this->info("Transferring roles...");
      $this->doTransferRoles();
      $this->info("Transferring roles... success!");

      $this->info("Transferring users...");
      $this->doTransferUsers();
      $this->info("Transferring users... success!");
    }

    // transfer files

    if ($this->transferFiles) {
      $this->info("Transferring files...");
      $this->doTransferFiles();
      $this->info("Transferring files... success!");
    }

    // TODO: this might be helpful?
    // $this->flushTargetSiteCache();

    return $this->output;
  }


  /**
   * Transfer the bulk of site data from source to target
   */
  protected function doBulkTransfer() {
    $this->info("Dumping source site data to {$this->dumpFilePath}...");
    $this->dumpSourceSiteData();
    $this->info("Dumping source site data... success!");

    $this->info("Loading dump file into the target site... ");
    $this->loadDumpIntoTarget();
    $this->info("Loading dump file into the target site... success!");

    // remove dump file from the server
    unlink($this->dumpFilePath);
  }

  /**
   * Dump the bulk of CiviCRM data from this site to be loaded into the target
   *
   * We exclude a few tables from the export because we want to keep what is
   * in place from the fresh Standalone install
   *
   * The php needs to be able to run exec and we need a safe temp location for
   * the dump file
   */
  protected function dumpSourceSiteData(): void {
    $source = \DB::parseDSN(CIVICRM_DSN);

    $dontExport = [
      // leave behind
      'civicrm_cache',
      // these shouldnt exist in the old DB
      // suppress here anyway to ensure we dont overwrite the clean tables from fresh Standalone install
      'civicrm_uf_match',
      // 'civicrm_user',
      'civicrm_user_role',
      'civicrm_role',
      'civicrm_session',
    ];

    $ignoreTablesString = implode(' ', array_map(function ($table) use ($source) {
      return '--ignore-table=' . escapeshellarg("{$source['database']}.{$table}");
    }, $dontExport));

    $dumpCommand = "mysqldump --skip-triggers -h" . escapeshellarg($source['hostspec']) . " -u" . escapeshellarg($source['username']) . " -p" . escapeshellarg($source['password']) . ' ' . $ignoreTablesString . ' ' . escapeshellarg($source['database']);

    $this->execOrThrow("{$dumpCommand} > {$this->dumpFilePath}");
  }

  /**
   * Check the target site is a working Standalone site
   */
  abstract protected function assureTargetSiteIsStandalone(): void;

  /**
   * Check the target site looks empty
   */
  abstract protected function assureTargetSiteIsEmpty(): void;

  /**
   * Get the standaloneusers schema version from the clean target site
   * @return ?int schema version or NULL
   */
  abstract protected function getTargetStandaloneusersVersion(): ?int;

  /**
   * Load data into the target site from the dump file
   */
  abstract protected function loadDumpIntoTarget(): void;

  /**
   * The source site will have standalonemigrate active in its civicrm_extension
   * table - but we don't want this once copied to the target site
   */
  abstract protected function purgeStandaloneMigrate(): void;

  /**
   * We need to enable standaloneusers extension on the target site
   * after we have replaced the `civicrm_extension` table from the source site
   *
   * @param ?int $schemaVersion to set when enabling standaloneusers - we want
   * to retain the schema version from the clean install on the target site
   * as this should match the standaloneusers table structures, which are also
   * retained from the clean target install
   */
  abstract protected function enableStandaloneusersExtension(?int $schemaVersion = NULL): void;

  /**
   * We need to add "pass" to the list of allowed authx credentials
   * to not break standaloneusers login form
   */
  abstract protected function enableAuthxPasswordCred(): void;

  /**
   * Clear default users and roles from the target site
   */
  abstract protected function clearUsersAndRoles(): void;

  /**
   * Copy roles from the current system to Standalone.
   *
   * NOTE: we translate the everyone role from CMS equivalent
   */
  protected function doTransferRoles() {
    $requiredRoles = [
      $this->sourceUf->getEveryoneRoleName() => [
        'target_name' => 'everyone',
        'required_permissions' => ['access password resets', 'authenticate with password'],
      ],
      'superuser' => [
        'target_name' => 'superuser',
        'required_permissions' => ['all CiviCRM permissions and ACLs'],
      ],
    ];
    foreach ($this->sourceUf->getRoles() as $name => $permissions) {
      $requiredRoleMatch = $requiredRoles[$name] ?? NULL;

      if ($requiredRoleMatch) {
        unset($requiredRoles[$name]);
        $name = $requiredRoleMatch['target_name'];
        $permissions = array_unique(array_merge($permissions, $requiredRoleMatch['required_permissions']));
      }
      $this->createStandaloneRole($name, $permissions);
    }

    // create any required roles that haven't been matched
    foreach ($requiredRoles as $role) {
      $this->createStandaloneRole($role['target_name'], $role['required_permissions']);
    }
  }

  /**
   * Create role on the target site
   *
   * @param string $name
   * @param string[] $permissions
   */
  abstract protected function createStandaloneRole(string $name, array $permissions): void;

  /**
   * Copy users from the current system to Standalone
   *
   * NOTE: this currently only transfers users WITH a CiviCRM user on the source
   * site. Is that sufficient?
   */
  protected function doTransferUsers() {
    $ufMatches = \Civi\Api4\UFMatch::get(FALSE)
      ->execute();

    $everyoneRoleName = $this->sourceUf->getEveryoneRoleName();

    foreach ($ufMatches as $ufMatch) {
      try {
        $cmsInfo = $this->sourceUf->getUser($ufMatch['uf_id']);

        // ensure every user has 'everyone' role and remove the untranslated everyone role name
        $cmsInfo['roles'] = array_filter($cmsInfo['roles'], function ($name) use ($everyoneRoleName) {
          return $name !== $everyoneRoleName;
        });
        $cmsInfo['roles'][] = 'everyone';
        $cmsInfo['roles'] = array_unique($cmsInfo['roles']);

        $this->createStandaloneUser(
          $ufMatch['id'],
          $ufMatch['domain_id'],
          $ufMatch['contact_id'],
          $cmsInfo['email'] ?: $ufMatch['uf_name'],
          $cmsInfo['username'] ?: $ufMatch['uf_name'],
          $cmsInfo['password'] ?: NULL,
          $cmsInfo['is_active'] ?? TRUE,
          $cmsInfo['language'] ?: '',
          $cmsInfo['timezone'] ?: '',
          $cmsInfo['roles'],
        );
      }
      catch (\Exception $e) {
        // Don’t fail for individual bad users
        $this->warning('Error transfering a user: ' . $e->getMessage());
      }
    }
  }

  /**
   * Add a user to the target site
   */
  abstract protected function createStandaloneUser(
    int $userId,
    int $domainId,
    int $contactId,
    string $email,
    ?string $userName = NULL,
    ?string $hashedPassword = NULL,
    bool $isActive = TRUE,
    string $language = '',
    string $timezone = '',
    array $roles = []
  ): void;

  /**
   * Transfer files to the target site
   */
  abstract protected function doTransferFiles(): void;

  protected function info(string $message): void {
    \Civi::log()->info($message);

    $this->output[] = 'INFO: ' . $message;

    if (PHP_SAPI) {
      print "\nINFO: {$message}\n";
    }
  }

  protected function warning(string $message): void {
    \Civi::log()->warning($message);

    $this->output[] = 'WARNING: ' . $message;

    if (PHP_SAPI) {
      print "\n\n\nWARNING: {$message}\n";
    }
  }

  /**
   * Run exec() but
   * - throw an exception if we don't get a success exit code.
   * - return the output var
   */
  protected function execOrThrow($cmd): array {
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    if ($exitCode) {
      throw new \CRM_Core_Exception("Error (exit code $exitCode) running: $cmd\n" . implode("\n", $output));
    }
    return $output;
  }
}

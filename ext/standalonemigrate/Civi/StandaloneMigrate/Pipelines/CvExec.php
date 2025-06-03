<?php

namespace Civi\StandaloneMigrate\Pipelines;

class CvExec extends CorePipeline {

  /**
   * @var string
   *
   * path to the root of the target standalone site (i.e. the folder containing civicrm.standalone.php)
   *
   * must be on the same server as the source site
   */
  protected string $targetSitePath;

  /**
   * @inheritdoc
   */
  public function __construct(array $params) {
    parent::__construct($params);

    $this->targetSitePath = $params['target_site_path'];
  }

  /**
   * @inheritdoc
   */
  public function checkRequirements(): array {
    $errors = parent::checkRequirements();

    if (!$this->targetSitePath) {
      $errors[] = 'Missing targetSitePath';
    }

    return $errors;
  }

//  /**
//   * Start by performing a clean install of the Standalone site
//   */
//  protected function cleanInstallOnTarget() {
//    if ($this->targetSitePath) {
//      Civi::log()->info("cleanStandaloneInstall in {$this->targetSitePath}");
//      $this->targetCv("core:install");
//    }
//  }

  /**
   * @inheritdoc
   */
  protected function assureTargetSiteIsStandalone(): void {
    $standaloneUsers = $this->targetCv('api4 User.get');

    if (!$standaloneUsers) {
      throw new \CRM_Core_Exception(
        "Target site does not appear to be running Standalone. Check your targetSitePath is correct, it is on the same server as your source site, and you have run a clean Standalone install before beginning migration."
      );
    }
  }

  /**
   * @inheritdoc
   */
  protected function assureTargetSiteIsEmpty(): void {
    $contacts = $this->targetCv("api4 Contact.get");
    $noContacts = count($contacts);
    if ($noContacts > 3) {
      throw new \CRM_Core_Exception("Found {$noContacts} contacts in the target site - are you sure you are pointing to a clean install?");
    }
    $contributions = $this->targetCv("api4 Contribution.get");
    $noContributions = count($contributions);
    if ($noContributions) {
      throw new \CRM_Core_Exception("Found {$noContributions} contributions in the target site - are you sure you are pointing to a clean install?");
    }
  }

  protected function getTargetStandaloneusersVersion(): ?int {
    return $this->targetCvSql(<<<SQL
      SELECT `schema_version` FROM `civicrm_extension` WHERE `full_name` = 'standaloneusers';
    SQL)[1];
  }

  /**
   * @inheritdoc
   */
  protected function loadDumpIntoTarget(): void {
    $this->targetCv("sql < {$this->dumpFilePath}");
  }

  /**
   * @inheritdoc
   */
  protected function purgeStandaloneMigrate(): void {
    $this->targetCvSql('
      DELETE FROM civicrm_extension WHERE full_name = \'standalonemigrate\';
    ');
  }

  /**
   * @inheritdoc
   */
  protected function enableStandaloneusersExtension(?int $schemaVersion = NULL): void {
    // for SQL we want literal NULL string
    $schemaVersion = $schemaVersion ?? 'NULL';
    // NOTE: would be nice if we could just $this->targetCv("en standaloneusers");
    // but at the moment the standaloneusers preInstall hook crashes on an installed site
    $this->targetCvSql(<<<SQL
      INSERT INTO `civicrm_extension` (`type`, `full_name`, `name`, `label`, `file`, `schema_version`, `is_active`)
      VALUES ('module', 'standaloneusers', 'Standalone Users', 'Standalone Users', 'standaloneusers', {$schemaVersion}, 1)
      ON DUPLICATE KEY UPDATE `is_active` = 1, `schema_version` = {$schemaVersion};
    SQL);

    // flush to update entity cache
    $this->targetCv('flush');
  }

  /**
   * @inheritdoc
   */
  protected function enableAuthxPasswordCred(): void {
    $this->targetCv("vset +l " . escapeshellarg('authx_login_cred[]=pass'));
  }

  /**
   * @inheritdoc
   */
  protected function clearUsersAndRoles(): void {
    $this->targetCv("api4 User.delete +w 'id>0'");
    $this->targetCv("api4 Role.delete +w 'id>0'");
  }

  /**
   * @inheritdoc
   */
  protected function createStandaloneRole(string $roleName, array $permissionNames): void {
    $params = escapeshellarg(json_encode([
      'values' => [
        'name' => $roleName,
        'label' => $roleName,
        'permissions:name' => $permissionNames,
      ],
    ]));
    $this->targetCv("api4 Role.create $params");
  }

  /**
   * @inheritdoc
   */
  protected function createStandaloneUser(
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
  ): void {

    // Note: api4 does not let you set the id for an entity; in fact it uses the id as a WHERE clause
    // so you end up thinking you got a successful create but actually it did nothing.

    // So we just let the api assign IDs - user ids from the previous system will NOT be preserved
    $params = escapeshellarg(json_encode([
      'values' => [
        // 'id' => $userId,
        // 'uf_id' => $userId,
        'domain_id' => $domainId,
        'uf_name' => $email,
        'contact_id' => $contactId,
        'username' => $userName,
        'hashed_password' => $hashedPassword,
        'is_active' => $isActive,
        'timezone' => $timezone,
        'language:name' => $language,
        'roles:name' => $roles,
      ],
    ]));

    $output = $this->targetCv("api4 User.create $params");

    // In some cases you may want to manually update the created user ID with SQL:

    // $parsed = json_decode(implode("", $output), TRUE);
    // $temporaryID = (int) $parsed[0]['id'];
    // if (!$temporaryID) {
    //   throw new \CRM_Core_Exception("Something fishy, did not get a valid id for User.create result");
    // }


    // \CRM_Utils_File::runSqlQuery($this->targetDsn, "UPDATE civicrm_uf_match SET id = $userId, uf_id = $userId WHERE id = $temporaryID");

    // now a 2nd call to write the roles. We can't do this in the first call because the user IDs will change.
    // $values = ['roles:name' => $roles];
    // $params = escapeshellarg(json_encode(['where' => [['id', '=', $userId]], 'values' => $values]));

    // $output = [];
    // $this->execOrThrow("CIVICRM_SETTINGS={$this->targetSitePath}private/civicrm.settings.php cv --cwd={$this->targetSitePath} -v api4 User.update checkPermissions=0 $params", $output);
  }

  /**
   * @inheritdoc
   */
  protected function doTransferFiles(): void {
    ## TODO: implement
    $this->warning("File transfer not yet implpemented");
  }

  /**
   * Run cv command on the target site
   *
   * Reminder: args in $cmd should be escapeshellarg'd
   */
  protected function targetCv($cmd): array {
    $cwd = escapeshellarg("--cwd={$this->targetSitePath}");
    $outputLines = $this->execOrThrow("cv {$cwd} {$cmd}");
    return json_decode(implode('', $outputLines)) ?: [];
  }

  /**
   * Pipe sql into cv sql on the target site
   */
  protected function targetCvSql($sql): array {
    $cwd = escapeshellarg("--cwd={$this->targetSitePath}");
    $sql = escapeshellarg($sql);
    $outputLines = $this->execOrThrow("echo $sql | cv {$cwd} sql");
    return $outputLines;
  }
}
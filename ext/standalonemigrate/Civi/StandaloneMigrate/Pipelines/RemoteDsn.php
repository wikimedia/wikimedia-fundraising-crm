<?php

namespace Civi\StandaloneMigrate\Pipelines;

class RemoteDsn extends CorePipeline {

  /**
   * @var string
   *
   * DSN for the target site
   */
  protected string $targetDsn;

  /**
   * @var array
   *
   * Holds the role IDs created in the standalone instance,
   * indexed by the role name.
   */
  private array $standaloneRoleNameToId = [];

  /**
   * @inheritdoc
   */
  public function __construct(array $params) {
    parent::__construct($params);

    $this->targetDsn = $params['target_dsn'] ?? NULL;
  }

  /**
   * @inheritdoc
   */
  public function checkRequirements(): array {
    $errors = parent::checkRequirements();

    if (!$this->targetDsn) {
      $errors[] = 'Missing targetDsn';
    }

    return $errors;
  }

  /**
   * @inheritdoc
   * @todo this implementation doesn't work
   */
  protected function assureTargetSiteIsStandalone(): void {
    # TODO: runSqlQuery doesnt return values
    $standaloneUsersActive = $this->targetSiteQuery("
      SELECT is_active FROM civicrm_extension WHERE file = 'standaloneusers';
    ") ?? TRUE;

    if (!$standaloneUsersActive) {
      throw new \CRM_Core_Exception(
        "Target site does not appear to be running Standalone. Check your targetSitePath is correct, it is on the same server as your source site, and you have run a clean Standalone install before beginning migration."
      );
    }
  }

  /**
   * @inheritdoc
   */
  protected function assureTargetSiteIsEmpty(): void {
    $noContacts = $this->targetSiteQuery("
      SELECT COUNT(id) FROM civicrm_contact;
    ");
    if ($noContacts > 2) {
      throw new \CRM_Core_Exception("Found {$noContacts} contacts in the target site - are you sure you are pointing to a clean install?");
    }
    $noContributions = $this->targetSiteQuery("
      SELECT COUNT(id) FROM civicrm_contribution;
    ");
    if ($noContributions) {
      throw new \CRM_Core_Exception("Found {$noContributions} contributions in the target site - are you sure you are pointing to a clean install?");
    }
  }

  /**
   * @todo runSqlQuery doesn't return values
   */
  protected function getTargetStandaloneusersVersion(): ?int {
    return $this->targetSiteQuery('SELECT schema_version FROM civicrm_extension WHERE full_name = \'standaloneusers\';');
  }

  /**
   * @inheritdoc
   */
  protected function loadDumpIntoTarget(): void {
    // load into target
    $target = \DB::parseDSN($this->targetDsn);

    $loadCommand = "mysql -h" . escapeshellarg($target['hostspec']) . " -u" . escapeshellarg($target['username']) . " -p" . escapeshellarg($target['password']) . ' ' . escapeshellarg($target['database']);
    $this->execOrThrow("{$loadCommand} < {$this->dumpFilePath}");
  }

  /**
   * @inheritdoc
   */
  protected function purgeStandaloneMigrate(): void {
    $this->targetSiteQuery('
      DELETE FROM civicrm_extension WHERE full_name = \'standalonemigrate\';
    ');
  }

  /**
   * @inheritdoc
   */
  protected function enableStandaloneusersExtension(?int $schemaVersion = NULL): void {
    // for SQL statement we want literal NULL string
    $schemaVersion = $schemaVersion ?? 'NULL';

    $this->targetSiteQuery(<<<SQL
        INSERT INTO `civicrm_extension` (`type`, `full_name`, `name`, `label`, `file`, `schema_version`, `is_active`)
        VALUES ('module', 'standaloneusers', 'Standalone Users', 'Standalone Users', 'standaloneusers', {$schemaVersion}, 1)
        ON DUPLICATE KEY UPDATE `is_active` = 1, `schema_version` = {$schemaVersion};
    SQL);
  }

  /**
   * @inheritdoc
   */
  protected function enableAuthxPasswordCred(): void {
    // first fetch values from current site (should match dumped data)
    // there may be multiple records based on the domain, we append 'pass' to all of them
    $authxSettingRecords = [];

    $select = \CRM_Utils_SQL_Select::from('civicrm_setting')
      ->select('id, value')
      ->where('name = "authx_login_cred"');

    $query = \CRM_Core_DAO::executeQuery($select->toSQL());

    $authxSettingRecords = $query->fetchAll('id', 'value');

    if (!$authxSettingRecords) {
      $serializedValue = serialize(['pass']);
      $this->targetSiteQuery("
          INSERT INTO `civicrm_setting` (name, value) VALUES ('authx_login_cred', '{$serializedValue}');
      ");
    }
    else {
      // unserialize, append, reserialize and then update the records by record id
      foreach ($authxSettingRecords as $recordId => $previousValue) {
        $unserialized = $previousValue ? \CRM_Utils_String::unserialize($previousValue) : [];
        $newValue = array_unique(array_merge($unserialized, ['pass']));
        $reserialized = serialize($newValue);
        $this->targetSiteQuery("
          UPDATE `civicrm_setting` SET value = '{$reserialized}' where `id` = {$recordId};
        ");
      }
    }
  }

  /**
   * @inheritdoc
   */
  protected function clearUsersAndRoles(): void {
    // Remove the default users but keep table structure
    $this->targetSiteQuery("
      DELETE FROM `civicrm_user_role`;
    ");
    $this->targetSiteQuery("
      DELETE FROM `civicrm_uf_match`;
    ");
    $this->targetSiteQuery("
      DELETE FROM `civicrm_role`;
    ");
  }

  /**
   * @inheritdoc
   */
  protected function createStandaloneRole(string $roleName, array $permissionNames): void {
    # TODO: implement
    $nextId = count($this->standaloneRoleNameToId);

    $permissionNames = serialize($permissionNames);

    $this->targetSiteQuery("
      INSERT INTO `civicrm_role`
        (
          `id`,
          `name`,
          `label`,
          `permissions`
        )
      VALUES
        (
          {$nextId},
          {$roleName},
          {$roleName},
          '{$permissionNames}'
        );
    ");

    $this->standaloneRoleNameToId[$roleName] = $nextId;
  }

  /**
   * Add a user to the target site
   *
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

    $userName = $userName ?? $email;

    // auto generated password wont be usable as you dont know the hash
    // but will ensure no one can log in until password is reset
    $hashedPassword = $hashedPassword ?? random_bytes(32);

    $this->targetSiteQuery("
      INSERT INTO `civicrm_uf_match`
        (
          `id`,
          `domain_id`,
          `uf_id`,
          `uf_name`,
          `contact_id`,
          `username`,
          `hashed_password`,
          `is_active`,
          `timezone`,
          `language`
        )
      VALUES
        (
          #`id`,
          {$userId},
          #`domain_id`,
          {$domainId},
          #`uf_id`,
          {$userId},
          #`uf_name`,
          '{$email}',
          #`contact_id`,
          {$contactId},
          #`username`,
          '{$userName}',
          #`hashed_password`,
          '{$hashedPassword}',
          #`is_active`,
          {$isActive},
          #`timezone`,
          '{$timezone}',
          #`language`
          '{$language}'
        );
      ");

    // Assign roles to user.
    $values = [];
    foreach ($roles as $roleName) {
      $roleID = (int) $this->standaloneRoleNameToId[$roleName];
      if ($roleID) {
        $values[] = "($userId, $roleID)";
      }
      else {
        $this->warning("Couldn't find created role ID for role name {$roleName}");
      }
    }
    if ($values) {
      $values = implode(",\n", $values);
      $this->targetSiteQuery(<<<SQL
        INSERT INTO `civicrm_user_role` (user_id, role_id)
        VALUES $values;
      SQL);
    }
    else {
      $this->warning("User $userId $email has no roles.");
    }
  }

  protected function doTransferFiles(): void {
    throw new \CRM_Core_Exception("Cannot transfer files with DSN method");
  }

  protected function targetSiteQuery($query) {
    return \CRM_Utils_File::runSqlQuery($this->targetDsn, $query);
  }

}

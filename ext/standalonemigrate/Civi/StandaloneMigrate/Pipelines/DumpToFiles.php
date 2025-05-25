<?php

namespace Civi\StandaloneMigrate\Pipelines;

class DumpToFiles extends CorePipeline {

  /**
   * @var string
   *
   * path to an empty directory to dump files
   */
  protected string $targetDirectory;

  protected array $roles = [];
  protected array $users = [];

  /**
   * @inheritdoc
   */
  public function __construct(array $params) {
    parent::__construct($params);

    $this->targetDirectory = $params['target_directory'];
  }

  protected function assureTargetSiteIsStandalone(): void {
    // assure file dump path is a writeable directory
    if (filetype($this->targetDirectory) !== 'dir' || !is_writable($this->targetDirectory)) {
      throw new \CRM_Core_Exception(
        "Please ensure target_directory is a set to a writable directory. Current value: $this->targetDirectory"
      );
    }
  }

  protected function assureTargetSiteIsEmpty(): void {
    // Should just have entries for '.' and '..'
    if (count(scandir($this->targetDirectory)) > 2) {
      throw new \CRM_Core_Exception(
        "Files found in $this->targetDirectory. Please point target_directory to an empty directory"
      );
    }
  }

  protected function getTargetStandaloneusersVersion(): ?int {
    return NULL;
  }

  protected function loadDumpIntoTarget(): void {
    // Just copies the ephemeral file to targetDirectory
    copy($this->dumpFilePath, $this->targetDirectory . DIRECTORY_SEPARATOR . 'civicrm.sql');
  }

  protected function purgeStandaloneMigrate(): void {
    // Not needed for file dump
  }

  protected function enableStandaloneusersExtension( ?int $schemaVersion = NULL ): void {
    // Not needed for file dump
  }

  protected function enableAuthxPasswordCred(): void {
    // Not needed for file dump
  }

  protected function clearUsersAndRoles(): void {
    // Not needed for file dump
  }

  protected function createStandaloneRole(string $name, array $permissions): void {
    $this->roles[$name] = $permissions;
    file_put_contents($this->targetDirectory . DIRECTORY_SEPARATOR . 'roles.json', json_encode($this->roles));
  }

  protected function createStandaloneUser( int     $userId,
                                           int     $domainId,
                                           int     $contactId,
                                           string  $email,
                                           ?string $userName = NULL,
                                           ?string $hashedPassword = NULL,
                                           bool    $isActive = TRUE,
                                           string  $language = '',
                                           string  $timezone = '',
                                           array   $roles = []
  ): void {
    $this->users[$userId] = [
      'email' => $email,
      'username' => $userName,
      'password' => $hashedPassword,
      'is_active' => $isActive,
      'timezone' => $timezone,
      'language' => $language,
      'roles' => $roles,
    ];
    file_put_contents($this->targetDirectory . DIRECTORY_SEPARATOR . 'users.json', json_encode($this->users));
  }

  protected function doTransferFiles(): void {
    // TODO: Implement doTransferFiles() method.
  }
}

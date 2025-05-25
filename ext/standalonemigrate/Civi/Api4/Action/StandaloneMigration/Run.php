<?php

namespace Civi\Api4\Action\StandaloneMigration;

use Civi\Api4\Generic\Result;

/**
 * Run database migration
 *
 * You should either pass a targetSitePath for a site on the same server,
 * the DSN string for a freshly installed Standalone site,
 * or targetDirectory to dump users, roles, and permissions to files
 *
 * NOTE: you should run a clean install on the target standalone site and
 * check it works as expected BEFORE running this migration
 *
 * WARNING: you will lose any existing data in the target database!
 */
class Run extends \Civi\Api4\Generic\AbstractAction {

  /**
   * @var string
   *
   * Working directory for the target site to use CV migration
   * NOTE: Must be on the same server as the source site
   */
  protected string $targetSitePath = '';

  /**
   * @var string
   *
   * DSN for the target Standalone site
   */
  protected string $targetDsn = '';

  /**
   * @var string
   *
   * Path of a local directory to dump users, roles, etc
   */
  protected string $targetDirectory = '';

  /**
   * @var string
   *
   * Path of a local directory to load dumped users, roles, etc
   */
  protected string $sourceDirectory = '';

  /**
   * @var bool
   *
   * Do you want to transfer users and roles from the existing site
   * to the new site? (otherwise users from the blank install will be retained)
   */
  protected bool $transferUsers = TRUE;

  /**
   * @var bool
   *
   * *not implemented*
   * Do you want to copy files from the existing site
   * to the new site?
   */
  protected bool $transferFiles = FALSE;

  /**
   * @var bool
   *
   * Do you want to transfer all tables to the new site?
   */
  protected bool $transferData = TRUE;

  /**
   * @var bool
   *
   * Skip checking that the target site is empty
   * before running the migration
   */
  protected bool $skipCheckingTargetEmpty = FALSE;

  /**
   * Run the migration
   *
   * @param \Civi\Api4\Generic\Result $result
   */
  public function _run(Result $result) {
    $migrationId = date('Y_m_d') . '__' . uniqid();
    \Civi::log()->info("Beginning migration $migrationId");

    $errors = $this->validateParams();

    if ($errors) {
      $result['errors'] = $errors;
      $result['is_error'] = 1;
      return;
    }

    $pipeline = $this->getPipeline($migrationId);

    $errors = $pipeline->checkRequirements();

    if ($errors) {
      $result['errors'] = $errors;
      $result['is_error'] = 1;
      return;
    }

    $output = $pipeline->run();
    $result['output'] = $output;
  }

  protected function getPipeline(string $migrationId): \Civi\StandaloneMigrate\Pipelines\CorePipeline {
    if ($this->targetSitePath) {
      \Civi::log()->info("Running StandaloneMigrate using cv exec pipeline");
      return new \Civi\StandaloneMigrate\Pipelines\CvExec([
        'migration_id' => $migrationId,
        'source_directory' => $this->sourceDirectory,
        'target_site_path' => $this->targetSitePath,
        'transfer_data' => $this->transferData,
        'transfer_users' => $this->transferUsers,
        'transfer_files' => $this->transferFiles,
        'skip_checking_target_empty' => $this->skipCheckingTargetEmpty,
      ]);
    }

    if ($this->targetDsn) {
      \Civi::log()->info("Running StandaloneMigrate using remote DSN pipeline");
      return new \Civi\StandaloneMigrate\Pipelines\RemoteDsn([
        'migration_id' => $migrationId,
        'source_directory' => $this->sourceDirectory,
        'target_dsn' => $this->targetDsn,
        'transfer_data' => $this->transferData,
        'transfer_users' => $this->transferUsers,
        'skip_checking_target_empty' => $this->skipCheckingTargetEmpty,
      ]);
    }

    if ($this->targetDirectory) {
      \Civi::log()->info("Running StandaloneMigrate using dump to files pipeline");
      return new \Civi\StandaloneMigrate\Pipelines\DumpToFiles([
        'migration_id' => $migrationId,
        'target_directory' => $this->targetDirectory,
        'transfer_data' => $this->transferData,
        // TODO: support transferFiles
        'transfer_users' => $this->transferUsers,
        'skip_checking_target_empty' => $this->skipCheckingTargetEmpty,
      ]);
    }
  }

  protected function validateParams(): array {
    $errors = [];

    if (!$this->targetSitePath && !$this->targetDsn && !$this->targetDirectory) {
      $errors[] = 'One of targetSitePath, targetDsn, or targetDirectory is required';
    }

    if (!$this->targetSitePath && $this->transferFiles) {
      $errors[] = 'Transferring files requires targetSitePath migration';
    }

    return $errors;
  }

}

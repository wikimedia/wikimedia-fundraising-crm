<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Import\DataSource;

use Civi\Api4\UserJob;
use CRM_ImportExtensions_ExtensionUtil as E;

/**
 * Objects that implement the DataSource interface can be used in CiviCRM
 * imports.
 *
 * @property string $importTableName
 * @property array $sqlFieldNames
 */
class PreviousImport extends \CRM_Import_DataSource {

  use DataSourceTrait;

  private string $importTableName;

  private array $sqlFieldNames;

  /**
   * Provides information about the data source.
   *
   * @return array
   *   collection of info about this data source
   */
  public function getInfo(): array {
    return [
      'title' => ts('Previous Import'),
      'template' => 'CRM/Import/DataSource/PreviousImport.tpl',
    ];
  }

  /**
   * This is function is called by the form object to get the DataSource's form
   * snippet.
   *
   * It should add all fields necessary to get the data
   * uploaded to the temporary table in the DB.
   *
   * @param \CRM_Import_Forms $form
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(\CRM_Import_Forms $form): void {
    if (\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer')) {
      $this->setUserJobID(\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer'));
    }
    $form->add('hidden', 'hidden_dataSource', 'CRM_Import_DataSource_PreviousImport');
    $jobs = UserJob::get()
      ->addWhere('job_type', 'LIKE', '%_import')
      ->execute();
    $imports = [];
    foreach ($jobs as $job) {
      if (!empty($job['metadata']['DataSource']['table_name'])
      && \CRM_Core_DAO::checkTableExists($job['metadata']['DataSource']['table_name'])
      ) {
        $imports[$job['id']] = ($job['name'] ?: $job['job_type']) . ' ' . $job['id'];
      }
    }

    if ($imports) {
      $form->add('select', 'import_id', E::ts('Select Import'), $imports, TRUE, ['class' => 'crm-select2 huge']);
    }
    else {
      $form->assign('upload_message', E::ts('There are no uploaded files available'));
    }
    $form->setDataSourceDefaults($this->getDefaultValues());
  }

  /**
   * Get default values for excel dataSource fields.
   *
   * @return array
   */
  public function getDefaultValues(): array {
    return ['skipColumnHeader' => 1];
  }

  /**
   * Initialize the datasource, based on the submitted values stored in the
   * user job.
   *
   * Generally this will include transferring the data to a database table.
   *
   * @throws \CRM_Core_Exception
   */
  public function initialize(): void {
    $userJob = UserJob::get()
      ->addWhere('id', '=', $this->getSubmittedValue('import_id'))
      ->execute()->single();
    $baseTable = $userJob['metadata']['DataSource']['table_name'];
    $table = \CRM_Utils_SQL_TempTable::build()->setDurable();
    $tableName = $table->getName();
    \CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS $tableName");
    $importTableName = $table->createWithQuery('SELECT * FROM ' . $baseTable)->getName();
    $trackingColumns = \CRM_Core_DAO::executeQuery('
      SHOW COLUMNS FROM ' . $tableName . ' LIKE "_%"');
    $trackingColumnUpdate = [];
    while ($trackingColumns->fetch()) {
      if ($trackingColumns->Field === '_status') {
        $trackingColumnUpdate[] = $trackingColumns->Field . '= "NEW"';
      }
      elseif (str_starts_with('_', $trackingColumns->Field) || $trackingColumns->Field === 'id') {
        $trackingColumnUpdate[] = $trackingColumns->Field . '= NULL';
      }
    }
    \CRM_Core_DAO::executeQuery('UPDATE ' . $importTableName
      . ' SET ' . implode(', ', $trackingColumnUpdate));

    \CRM_Core_DAO::executeQuery(
      'ALTER TABLE ' . $tableName
      . ' ADD INDEX(_id),
      ADD INDEX(_status),
      ADD INDEX(_entity_id)'
    );

    $this->updateUserJobDataSource([
      'table_name' => $importTableName,
      'column_headers' => $userJob['metadata']['DataSource']['column_headers'],
      'number_of_columns' => $userJob['metadata']['DataSource']['number_of_columns'],
    ]);
    $this->importTableName = $importTableName;
  }

  /**
   * Get array array of field names that may be submitted for this data source.
   *
   * The quick form for the datasource is added by ajax - meaning that
   * QuickForm
   * does not see them as part of the form. However, any fields listed in this
   * array will be taken from the `$_POST` and stored to the UserJob under the
   * DataSource key.
   *
   * @return array
   */
  public function getSubmittableFields(): array {
    return ['import_id'];
  }

  /**
   * Get the table name for the import job.
   *
   * @return string|null
   *
   * @throws \CRM_Core_Exception
   */
  protected function getTableName(): ?string {
    if (!isset($this->importTableName)) {
      $importTableName = parent::getTableName();
      if ($importTableName) {
        // Only set if not NULL, due to type hint.
        $this->importTableName = $importTableName;
      }
      return $importTableName;
    }
    return $this->importTableName;
  }

}

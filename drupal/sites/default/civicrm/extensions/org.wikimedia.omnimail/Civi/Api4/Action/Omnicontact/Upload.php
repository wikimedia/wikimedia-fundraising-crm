<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\OmnimailJobProgress;
use GuzzleHttp\Client;
use League\Csv\Reader;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setIsAlreadyUploaded(bool $isAlreadyUploaded)
 * @method $this setCsvFile(string $csvFile)
 * @method $this setPrefix(string $prefix)
 * @method $this setSourceFolder(string $sourceFolder)
 * @method $this setMappingFile(string $xmlFile)
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client $client) Generally Silverpop....
 * @method null|Client getClient()
 *
 * @package Civi\Api4
 */
class Upload extends AbstractAction {

  /**
   * @var string
   */
  protected $mailProvider = 'Silverpop';

  /**
   * @var object
   */
  protected $client;

  /**
   * Path to the csv file.
   *
   * In most cases this should be the full path but only the file name
   * is used if isAlreadyUploaded is true.
   *
   * @var string
   */
  protected string $csvFile = '';

  /**
   * Path to the mapping xml file.
   *
   * In most cases this should be the full path but only the file name
   * is used if isAlreadyUploaded is true.
   *
   * @var string
   */
  protected string $mappingFile = '';

  /**
   * Folder containing CSV files. If not explicitly specifying a single file with $csvFile,
   * the 'last' file in this folder with a matching $prefix will be selected.
   *
   * @var string
   */
  protected string $sourceFolder = '';

  /**
   * Prefix for CSV file names. Ignored when a single file is specified with $csvFile.
   * See $sourceFolder.
   *
   * @var string
   */
  protected string $prefix = '';

  /**
   * Database ID.
   *
   * Defaults to the one defined in the CiviCRM setting..
   *
   * @var int
   */
  protected string $databaseID = '';

  private bool $mappingFileWasGenerated = FALSE;

  /**
   * Get the remote database ID.
   *
   * @return int
   */
  public function getDatabaseID(): int {
    if (!$this->databaseID) {
      $this->databaseID = (int) (\Civi::settings()->get('omnimail_credentials')[$this->getMailProvider()]['database_id'][0] ?? 0);
    }
    return $this->databaseID;
  }

  /**
   * Is the file already uploaded.
   *
   * It is helpful to set this to TRUE during tests as it
   * will not attempt sftp.
   *
   * @var bool
   */
  protected bool $isAlreadyUploaded = FALSE;

  public function getMappingFile() {
    if (!$this->mappingFile) {
      $this->createMappingFile();
    }
    if (!$this->mappingFileWasGenerated) {
      $this->throwIfNotInAllowedFolder($this->mappingFile);
    }
    return $this->mappingFile;
  }

  public function getCsvFile(): string {
    if (!$this->csvFile) {
      if (!$this->prefix || !$this->sourceFolder) {
        throw new \CRM_Core_Exception('Must set either csvFile or both prefix and sourceFolder');
      }
      $matchedFiles = \CRM_Utils_File::findFiles($this->sourceFolder, $this->prefix . '-*.csv');
      if (!$matchedFiles) {
        throw new \CRM_Core_Exception('No files found matching prefix inside sourceFolder');
      }
      sort($matchedFiles);
      $this->csvFile = array_pop($matchedFiles);
    }
    $this->throwIfNotInAllowedFolder($this->csvFile);
    return $this->csvFile;
  }

  protected function createMappingFile(): void {
    $reader = Reader::createFromPath($this->getCsvFile());
    $reader->setHeaderOffset(0);
    $headers = $reader->getHeader();
    $temporaryDirectory = sys_get_temp_dir();
    $this->mappingFile = $temporaryDirectory. '/' . str_replace('.csv', '.xml', basename($this->getCsvFile()));
    $file = fopen($this->mappingFile, 'wb');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
  <LIST_IMPORT>
   <LIST_INFO>
      <ACTION>ADD_AND_UPDATE</ACTION>
      <LIST_ID>' . $this->getDatabaseID()  . '</LIST_ID>
      <FILE_TYPE>0</FILE_TYPE>
      <HASHEADERS>true</HASHEADERS>
   </LIST_INFO>
   <SYNC_FIELDS>
      <SYNC_FIELD>
         <NAME>EMAIL</NAME>
      </SYNC_FIELD>
   </SYNC_FIELDS>
   <MAPPING>
      ';
    foreach ($headers as $index => $header) {
      $xml .= '
      <COLUMN>
         <INDEX>' . ($index + 1) . '</INDEX>
         <NAME>' . $header . '</NAME>
         <INCLUDE>true</INCLUDE>
      </COLUMN>
      ';
    }

    $xml .= '
    </MAPPING>
</LIST_IMPORT>';
    fwrite($file, $xml);
    $this->mappingFileWasGenerated = TRUE;
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $omniObject = new \CRM_Omnimail_Omnicontact([
      'mail_provider' => $this->getMailProvider(),
    ]);
    $response = $omniObject->upload([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'mapping_file' => $this->getMappingFile(),
      'csv_file' => $this->getCsvFile(),
      'is_already_uploaded' => $this->isAlreadyUploaded,
    ]);
    if (!$response->getIsSuccess()) {
      throw new \CRM_Core_Exception('csv mapping upload failed');
    }

    \Civi::log($this->getMailProvider())
      ->notice('Import {job_id} started with file: {csv}', [
        'job_id' => $response->getJobId(),
        'type' => 'job initiated',
        'csv' => basename($this->getCsvFile()),
        'url' => $this->getUrlBase() . $response->getJobId(),
      ]);
    OmnimailJobProgress::create(FALSE)
      ->setValues([
        'mailing_provider' => $this->getMailProvider(),
        'job' => 'data_upload',
        'job_identifier' => $response->getJobId(),
      ])
      ->execute();
    $result[] = ['job_id' => $response->getJobId()];
  }

  public function getUrlBase(): string {
    return 'https://cloud.goacoustic.com/campaign-automation/Data/Data_jobs?cuiOverrideSrc=https%253A%252F%252Fcampaign-us-4.goacoustic.com%252FdataJobs.do%253FisShellUser%253D1%2526action%253DdataJobsDetail%2526triggerId%253D';
  }

  public function fields(): array {
    return [];
  }

  protected function throwIfNotInAllowedFolder(string $csvFile): void {
    foreach (\Civi::settings()->get('omnimail_allowed_upload_folders') as $folder) {
      if (\CRM_Utils_File::isChildPath($folder, $csvFile)) {
        return;
      }
    }
    throw new \CRM_Core_Exception(
      "The csv file '$csvFile' is not in one of the allowed folders. " .
      'Please check omnimail_allowed_upload_folders in CiviCRM settings'
    );
  }

}

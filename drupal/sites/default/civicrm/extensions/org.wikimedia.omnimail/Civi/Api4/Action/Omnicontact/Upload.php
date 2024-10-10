<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use GuzzleHttp\Client;
use League\Csv\Reader;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setIsAlreadyUploaded(bool $isAlreadyUploaded)
 * @method $this setCsvFile(string $csvFile)
 * @method string getCsvFile()
 * @method $this setMappingFile(string $xmlFile)
 * @method $this setDatabaseID(int $databaseID)
 * @method $this setMailProvider(string $mailProvider) Generally Silverpop....
 * @method string getMailProvider()
 * @method $this setClient(Client$client) Generally Silverpop....
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
   *
   * @required
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
   * Database ID.
   *
   * Defaults to the one defined in the CiviCRM setting..
   *
   * @var int
   */
  protected string $databaseID = '';

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
    return $this->mappingFile;
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
    $result[] = ['job_id' => $response->getJobId()];
  }

  public function fields(): array {
    return [];
  }

}

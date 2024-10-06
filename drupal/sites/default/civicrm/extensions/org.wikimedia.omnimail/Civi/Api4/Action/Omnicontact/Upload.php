<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use GuzzleHttp\Client;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setIsAlreadyUploaded(bool $isAlreadyUploaded)
 * @method $this setCsvFile(string $csvFile)
 * @method string getCsvFile()
 * @method $this setMappingFile(string $xmlFile)
 * @method string getMappingFile()
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
   *
   * @required
   */
  protected string $mappingFile = '';

  /**
   * Is the file already uploaded.
   *
   * It is helpful to set this to TRUE during tests as it
   * will not attempt sftp.
   *
   * @var bool
   */
  protected bool $isAlreadyUploaded = FALSE;

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

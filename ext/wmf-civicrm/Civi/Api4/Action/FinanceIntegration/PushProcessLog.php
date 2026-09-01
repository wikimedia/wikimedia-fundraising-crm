<?php
namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\FinanceIntegration\Connection;

/**
 * @method $this setIsEndowment(bool $isEndowment)
 * @method $this setIsStaging(bool $isStaging)
 * @method $this setName(string $name)
 * @method $this setDescription(string $description)
 * @method $this setFileFormat(string $fileFormat)
 * @method $this setStatus(string $status)
 * @method $this setSummary(string $summary)
 * @method $this setComment(string $comment)
 * @method $this setProcessType(string $processType)
 * @method $this setJobType(string $jobType)
 * @method $this setProcessedPercentage(int $processedPercentage)
 * @method $this setUser(string $user)
 * @method $this setDocid(string $docid)
 * @method $this setResultsUrl(string $resultsUrl)
 */
class PushProcessLog extends AbstractAction {

  /**
   * Is Endowment
   *
   * Is the connection we want to use the Endowment connection.
   *
   * (ie which Sage instance are we connecting to)
   *
   * @var bool
   */
  protected bool $isEndowment = FALSE;

  /**
   * Are we connecting to the staging instance?
   *
   * @var bool
   */
  protected bool $isStaging = TRUE;

  /**
   * Log name.
   *
   * @required
   *
   * @var string
   */
  protected string $name = '';

  /**
   * Description.
   *
   * @var string
   */
  protected string $description = '';

  /**
   * @var string
   */
  protected string $fileFormat = '';

  /**
   * process_log status - e.g. Complete or Failed.
   *
   * @var string
   */
  protected string $status = 'Complete';

  /**
   * @var string
   */
  protected string $summary = '';

  /**
   * @var string
   */
  protected string $comment = '';

  /**
   * @var string
   */
  protected string $processType = 'SmashPig';

  /**
   * @var string
   */
  protected string $jobType = 'Journal Import';

  /**
   * @var int
   */
  protected int $processedPercentage = 100;

  /**
   * User to record against the log.
   *
   * Defaults to the username from the Intacct connection credentials if
   * not explicitly set.
   *
   * @var string
   */
  protected string $user = '';

  /**
   * @var string
   */
  protected string $docid = '';

  /**
   * Link to the underlying result (e.g. the webURL of the journal entry
   * this log relates to).
   *
   * @var string
   */
  protected string $resultsUrl = '';

  public function _run(Result $result): void {
    $connection = $this->getConnection();
    $xmlApi = $connection->getXmlApi();

    $fields = [
      'name' => $this->name,
      'description' => $this->description,
      'file_format' => $this->fileFormat,
      'status' => $this->status,
      'summary' => $this->summary,
      'comment' => $this->comment,
      'process_type' => $this->processType,
      'job_type' => $this->jobType,
      'processed_percentage' => $this->processedPercentage,
      'user' => $this->user !== '' ? $this->user : $connection->getUsername(),
      'docid' => $this->docid,
      'results_url' => $this->resultsUrl,
    ];

    // Don't send empty optional fields.
    $fields = array_filter($fields, static fn($value) => $value !== '');

    $content = $xmlApi->createProcessLog($fields);
    $response = $xmlApi->execute($content);

    $result[] = [
      'success' => TRUE,
      'response' => $response,
    ];
  }

  /**
   * Get the Sage Intacct connection.
   *
   * Protected to allow unit tests to substitute a test connection.
   *
   * @return \Civi\FinanceIntegration\Connection
   */
  protected function getConnection(): Connection {
    return new Connection(
      $this->isEndowment ? 'endowment' : 'wmf',
      $this->isStaging
    );
  }

}

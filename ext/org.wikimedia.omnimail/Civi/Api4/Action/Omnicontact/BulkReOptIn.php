<?php
namespace Civi\Api4\Action\Omnicontact;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_Core_DAO;
use CRM_Omnimail_ExtensionUtil as E;

/**
 * Re opt in emails in Acoustic from a table.
 * This also removes the emails from the Master Suppression List.
 * The MSL must also be rebuilt in Silverpop export or else the same emails
 * will be opted out again on the next nightly import.
 *
 * @method $this setTableName(string $tableName)
 * @method string getTableName()
 * @method $this setLimit(int $count)
 * @method string getLimit()
 * @method int getGroupIdentifier()
 * @method $this setGroupIdentifier(array $groupIdentifier)
 *
 * @package Civi\Api4
 */
class BulkReOptIn extends AbstractAction {

  /**
   * Table name with list of emails to re opt in, including database prefix.
   *
   * CREATE TABLE emails_to_opt_in (
   *   email VARCHAR(255) PRIMARY KEY,
   *   processed TINYINT(1) NOT NULL DEFAULT 0
   * );
   *
   * processed will be set to 2 if Acoustic API call fails.
   *
   * @var string
   */
  protected string $tableName;

  /**
   * Number of emails to process.
   *
   * @var int|null
   */
  protected ?int $limit = NULL;

  /**
   * Acoustic contact lists ids to add the contact to.
   *
   * @var array
   */
  protected $groupIdentifier = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $success = $failure = 0;
    $limit = $this->getLimit();
    $groupIdentifier = $this->getGroupIdentifier();
    $getsql = "SELECT email
        FROM " . $this->getTableName() . "
        WHERE processed = 0
        LIMIT 1;
      ";
    $setsql = "UPDATE " . $this->getTableName() . "
        SET processed = %1
        WHERE email = %2;
      ";

    while ($success + $failure < $limit || $limit === NULL) {
      $email = \CRM_Core_DAO::singleValueQuery($getsql);
      if ($email) {
        try {
          $optIn = \Civi\Api4\Omnicontact::create(FALSE)
            ->setEmail($email)
            ->addValue('is_opt_out', FALSE)
            ->setGroupIdentifier($groupIdentifier)
            ->execute()->first();
          \CRM_Core_DAO::executeQuery($setsql, [1 => [1, 'Integer'], 2 => [$email, 'String']]);
          $success++;
        }
        catch (\Exception $e) {
          // If the Acoustic API times out, retry a few times.
          if (str_starts_with($e->getMessage(),'cURL error') && $failure <= 10) {
            $failure++;
            sleep(120);
          }
          else {
            \CRM_Core_DAO::executeQuery($setsql, [1 => [2, 'Integer'], 2 => [$email, 'String']]);
            $failure++;
          }
        }
      }
      else {
        break;
      }
    }
    $result[] = ['success_count' => $success, 'failure_count' => $failure];
  }

}

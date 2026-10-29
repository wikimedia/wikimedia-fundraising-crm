<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Email;
use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\WMFAudit;

/**
 * @method $this setEmailSummaryAddress(string $email)
 */
class GenerateBatch extends BasicBatchAction {

  /**
   * Address to email the summary to.
   *
   * Defaults to the logged-in user's email if not given.
   *
   * @var string|null
   */
  protected ?string $emailSummaryAddress = NULL;

  /**
   * Generates the batch's journal and pushes it to Intacct, closing the
   * batch (status Exported) on success.
   *
   * @inheritDoc
   *
   * @throws \CRM_Core_Exception
   */
  public function doTask($item): array {
    return WMFAudit::generateBatch($this->checkPermissions)
      ->setIsDryRun(FALSE)
      ->setOutputMethod('api')
      ->setIsOutputCsv(TRUE)
      ->setEmailSummaryAddress($this->emailSummaryAddress ?? $this->getCurrentUserEmail())
      ->setId($item['id'])
      ->execute()->first() ?? [];
  }

  /**
   * Primary, non-on-hold email of the logged-in user, if any.
   *
   * @throws \CRM_Core_Exception
   */
  private function getCurrentUserEmail(): ?string {
    return Email::get(FALSE)
      ->addWhere('contact_id', '=', \CRM_Core_Session::getLoggedInContactID())
      ->addWhere('on_hold', '=', 0)
      ->addWhere('email', '<>', '')
      ->addSelect('email')
      ->addOrderBy('is_primary', 'DESC')
      ->execute()->first()['email'] ?? NULL;
  }

}

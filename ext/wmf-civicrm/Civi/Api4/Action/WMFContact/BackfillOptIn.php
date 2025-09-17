<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\Contact;
use Civi\Api4\GroupContact;

/**
 * Class BackfillOptIn.
 *
 * Update contact info from queue consume
 *
 * @method $this setEmail(string $email)
 * @method string getEmail()
 * @method $this setDate(int $date)
 * @method int|null getDate()
 * @method $this setOpt_in(bool $optIn)
 * @method bool|null getOpt_in()
 *
 * */
class BackfillOptIn extends AbstractAction {

  /**
   * email
   * @required
   * @var string
   */
  protected $email;

  /**
   * unix timestamp of last opt-in found in logs
   * @required
   * @var int
   */
  protected $date;

  /**
   * value of opt_in from last log message.
   *
   * @var bool
   */
  protected $opt_in;

  /**
   * @throws \CRM_Core_Exception
   * @throws UnauthorizedException
   */
  public function _run(Result $result): void {
    $allContactIDsWithEmail = Email::get(FALSE)
      ->addWhere('email', '=', $this->email)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addSelect('contact_id')
      ->execute()->getArrayCopy();
    $contactIDList = array_column($allContactIDsWithEmail, 'contact_id');
    $hasChangeToOptInAfterOurDate = FALSE;

    if (!empty($contactIDList)) {
      $query = 'select entity_id, log_date, opt_in from log_civicrm_value_1_communication_4 where entity_id in ('
        . implode(',', $contactIDList) . ') order by entity_id asc, log_date asc';
      $ourDate = (new \DateTime('@' . $this->getDate()))->format('Y-m-d H:i:s');
      $currentContactId = 0;
      $existingValue = NULL;
      foreach (\CRM_Core_DAO::executeQuery($query)->fetchAll() as $logEntry) {
        if ($currentContactId <> $logEntry['entity_id']) {
          // Reset existing value and current contact ID every time we hit a new contact ID
          $currentContactId = (int) $logEntry['entity_id'];
          $existingValue = NULL;
        }
        if ($logEntry['opt_in'] !== $existingValue && $logEntry['log_date'] >= $ourDate) {
          // Note that this will count it as a change if a new contact is created with non-null opt-in
          // and a different email after our last message date, but then updated to this email. Unlikely
          // but this seems like acceptable behavior.
          $hasChangeToOptInAfterOurDate = TRUE;
          \Civi::log(
            "Found change to opt_in for contact_id {$logEntry['entity_id']} on date {$logEntry['log_date']}, skipping " .
            "opt_in update for email $this->email"
          );
          break;
        }
        $existingValue = $logEntry['opt_in'];
      }
      if (!$hasChangeToOptInAfterOurDate) {
        $this->applyOptInChange($contactIDList);
      }
    }
    $result['contact_ids'] = $contactIDList;
    $result['applied_change'] = !$hasChangeToOptInAfterOurDate;
  }

  protected function applyOptInChange(array $contactIDList): void {
    foreach ($contactIDList as $contactID) {
      if ($this->getOpt_in()) {
        Contact::update(FALSE)
          ->addWhere('id', '=', $contactID)
          ->setValues(['Communication.opt_in' => $this->getOpt_in()])
          ->execute();
        $groupName = 'opt_in_backfill';
      }
      else {
        // Here we'll just add them to a group
        $groupName = 'opt_out_backfill';
      }
      GroupContact::create(FALSE)
        ->addValue('contact_id', $contactID)
        ->addValue('group_id.name', $groupName)
        ->execute();
    }
  }
}

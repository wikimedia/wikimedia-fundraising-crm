<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WorkflowMessage;
use CRM_Core_PseudoConstant;

/**
 * Notifies annual recurring donors that their donation is soon to be charged
 * @method setDays(int $days) set the number of days
 * @method setBatch(int $batch) set the number of rows to update
 */
class PrenotifyAnnualDonors extends AbstractAction {

  /**
   * @var int number of days before charge to send email
   */
  protected $days = 15;

  /**
   * @var int number of emails to send, 0 sends to all eligible donations
   */
  protected $batch = 0;

  public function _run( Result $result ) {
    $activityType = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Recurring Prenotification');
    // Get annual recurring contributions with next charge date less than $days days in the future
    // and no activity of type 'Recurring Prenotification' in the last 3 months
    $recurringQuery = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id')
      ->addJoin(
        'Activity AS activity',
        'LEFT',
        ['activity.source_record_id', '=', 'id'],
        ['activity.activity_type_id', '=', $activityType],
        ['activity.activity_date_time', '>', '-3 months'],
      )
      ->addWhere('frequency_unit', '=', 'year')
      ->addWhere('next_sched_contribution_date', 'BETWEEN', ['now', "+$this->days days"])
      ->addWhere(
        'contribution_status_id:name',
        'IN',
        [
          'Pending',
          'Overdue',
          'In Progress',
          'Failing'
        ]
      )->addWhere(
        'cancel_date', 'IS NULL'
      )->addWhere(
        'activity.id', 'IS NULL'
      )->addOrderBy('next_sched_contribution_date');
    if ($this->batch) {
      $recurringQuery->setLimit($this->batch);
    }
    $result['notifications'] = [];
    $result['send_success_count'] = $result['send_failure_count'] = 0;
    foreach ($recurringQuery->execute() as $recurringContribution) {
      $sendResult = $this->sendNotification($recurringContribution, $activityType);
      if ($sendResult['send_successful']) {
        $result['send_success_count']++;
      } else {
        $result['send_failure_count']++;
      }
      unset ($sendResult['html']); // keep the output reasonable
      $sendResult['contactID'] = $recurringContribution['contact_id'];
      $result['notifications'][$recurringContribution['id']] = $sendResult;
    }
  }

  function sendNotification(array $recurringContribution, int $activityType): array {
    $email = $this->renderEmail($recurringContribution);

    // If no template exists just back out.
    if (empty($email['msg_html']) && empty($email['msg_text'])) {
      return ['send_successful' => FALSE];
    }
    list($domainEmailName, $domainEmailAddress) = \CRM_Core_BAO_Domain::getNameAndEmail();
    $params = $return = [
      'html' => $email['msg_html'] ?? NULL,
      'text' => $email['msg_text'] ?? NULL,
      'subject' => $email['msg_subject'],
      'toEmail' => $email['email'],
      'toName' => $email['display_name'],
      'from' => "$domainEmailName <$domainEmailAddress>",
    ];
    if (\CRM_Utils_Mail::send($params)) {
      Activity::create()->setCheckPermissions(FALSE)->setValues([
        'target_contact_id' => $recurringContribution['contact_id'],
        'source_contact_id' => \CRM_Core_Session::getLoggedInContactID() ?? $recurringContribution['contact_id'],
        'subject' => 'Annual recurring prenotification : ' . $email['msg_subject'],
        'details' => $email['msg_html'],
        'activity_type_id' => $activityType,
        'activity_date_time' => 'now',
        'source_record_id' => $recurringContribution['id'],
      ])->execute();
      $return['send_successful'] = TRUE;
    } else {
      $return['send_successful'] = FALSE;
    }
    return $return;
  }

  protected function renderEmail(array $recurringContribution) {
    $email = Email::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('contact_id', '=', $recurringContribution['contact_id'])
      ->addWhere('email', '<>', '')
      ->setSelect(['contact_id.preferred_language', 'email', 'contact_id.display_name'])
      ->addOrderBy('is_primary', 'DESC')
      ->execute()->first();

    if (empty($email)) {
      return FALSE;
    }

    $supportedLanguages = $this->getSupportedLanguages();
    if (!empty($email['contact_id.preferred_language'])
      && strpos($email['contact_id.preferred_language'], 'en') !== 0
      && !in_array($email['contact_id.preferred_language'], $supportedLanguages, TRUE)
    ) {
      // Temporary early return for non translated languages while we test them.
      // The goal is to create a template for a bunch of languages - the
      // syntax to create is
      // \Civi\Api\MessageTemplate::create()->setLanguage('fr_FR')
      // fall back not that well thought through yet.
      return FALSE;
    }

    $rendered = WorkflowMessage::render(FALSE)
      ->setLanguage($email['contact_id.preferred_language'])
      ->setValues(['contributionRecurID' => $recurringContribution['id'], 'contactID' => $recurringContribution['contact_id']])
      ->setWorkflow('annual_recurring_prenotification')->execute()->first();

    return [
      'email' => $email['email'],
      'display_name' => $email['contact_id.display_name'],
      'language' => $email['contact_id.preferred_language'],
      'msg_html' => $rendered['html'],
      'msg_subject' => $rendered['subject'],
      'msg_text' => $rendered['text'],
    ];
  }

  /**
   * @return string[]
   */
  protected function getSupportedLanguages(): array {
    if (!isset(Civi::$statics[__CLASS__]['languages'])) {
      $templateID = Civi\Api4\MessageTemplate::get(FALSE)
        ->addWhere('workflow_name', '=', 'annual_recurring_prenotification')
        ->addSelect('id')
        ->execute()
        ->first()['id'];
      $supportedLanguages = (array) Civi\Api4\Translation::get(FALSE)
        ->setWhere([
          ['entity_id', '=', $templateID],
          ['entity_table', '=', 'civicrm_msg_template'],
          ['status_id:name', '=', 'active'],
        ])->addSelect('language')->execute()->indexBy('language');
      Civi::$statics[__CLASS__]['languages'] = array_keys($supportedLanguages);
    }
    return Civi::$statics[__CLASS__]['languages'];
  }
}

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
 * Sends the second recurring failure email $days after the first one has been sent
 * @method setDays(int $days) set the number of after the first email is sent
 * @method setBatch(int $batch) set the number of rows to update
 */
class SendSecondRecurringFailureEmail extends AbstractAction {

  /**
   * @var int how many days after the first email to send the second email
   */
  protected $days = 5;

  /**
   * @var int how far back to check for the first email activity, in relation to $days
   * example: $days = 5, $daysmax = 10, BETWEEN now - 10 days and now - 5 days
   */
  protected $maxdays = 50;

  /**
   * @var int number of emails to send, 0 sends to all eligible donations
   */
  protected $batch = 0;

  public function _run( Result $result ) {
    $firstFailureActivityType = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'First Recurring Failure Email');
    $secondFailureActivityType = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Second Recurring Failure Email');

    // Get the recurrings that have the first failure email sent at least $days ago with no second failure email
    $recurringQuery = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id')
      ->addWhere('contribution_status_id:name', '=', 'Cancelled')
      ->addWhere('cancel_date', 'BETWEEN', ["now -$this->maxdays days","now -$this->days days "])
      ->addJoin(
        'Activity AS first',
        'INNER',
        ['first.source_record_id', '=', 'id'],
        ['first.activity_type_id', '=', $firstFailureActivityType],
        ['first.activity_date_time',  'BETWEEN', ["now -$this->maxdays days","now -$this->days days "]])
      ->addJoin(
        'Activity AS second',
        'EXCLUDE',
        ['second.source_record_id', '=', 'id'],
        ['second.activity_type_id', '=', $secondFailureActivityType]);

    $result['notifications'] = [];
    $result['send_success_count'] = $result['send_failure_count'] = 0;
    foreach ($recurringQuery->execute() as $recurringContribution) {
      // Matching the behavior of the first failure email, don't send if there are any other active recurrings
      $recurringCheck = ContributionRecur::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_id','=',$recurringContribution['contact_id'])
        ->addWhere(
          'contribution_status_id:name',
          'IN',
          [
            'Pending',
            'Overdue',
            'In Progress',
            'Failing'
          ]
        )->execute()->count();

      if ($recurringCheck > 0) {
        continue;
      }
      // Send the email
      $sendResult = $this->sendNotification($recurringContribution, $secondFailureActivityType);
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
        'subject' => $email['msg_subject'],
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
      ->setWorkflow('recurring_second_failed_message')->execute()->first();

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
        ->addWhere('workflow_name', '=', 'recurring_failed_message')
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

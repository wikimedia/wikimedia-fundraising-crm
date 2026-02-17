<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
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
      $sendResult = Civi\Api4\FailureEmail::send()
        ->setContactID($recurringContribution['contact_id'])
        ->setContributionRecurID($recurringContribution['id'])
        ->setWorkflow('recurring_second_failed_message')
        ->execute()->first();
      if ($sendResult['send_successful']) {
        $result['send_success_count']++;
      } else {
        $result['send_failure_count']++;
      }
      unset ($sendResult['msg_html']);
      unset ($sendResult['msg_subject']);
      unset ($sendResult['html']);
      unset ($sendResult['text']);// keep the output reasonable
      $sendResult['contactID'] = $recurringContribution['contact_id'];
      $result['notifications'][$recurringContribution['id']] = $sendResult;
    }
  }

}

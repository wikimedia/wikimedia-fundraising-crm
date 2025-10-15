<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use SmashPig\PaymentProviders\PaymentProviderFactory;

/**
 * Changes status to cancelled for any recurring contributions
 * that have their next scheduled date more than 60 days in the past
 * (meaning they have not had a successful payment in at least 60 days)
 * @method setDays(int $days) set the number of days
 */
class CancelInactives extends AbstractAction {

  /**
   * @var int number of days past due to consider inactive
   */
  protected $days = 60;

  public function _run( Result $result ) {
    $limitDate = date('Y-m-d', strtotime("-$this->days days"));
    $inactives = ContributionRecur::get(FALSE)
      ->setSelect(
        ['id', 'contact_id', 'payment_processor_id:name', 'next_sched_contribution_date', 'start_date', 'trxn_id']
      )
      ->addWhere('contribution_status_id', 'NOT IN', [1,3,4])
      ->addWhere('frequency_unit', '=', 'month')
      ->addWhere('frequency_interval', '=', '1')
      ->addWhere('next_sched_contribution_date', '<', $limitDate)
      ->execute();

    foreach($inactives as $inactive) {
      $processor = $inactive['payment_processor_id:name'];
      if ($processor && RecurHelper::gatewayManagesOwnRecurringSchedule($processor)) {
        \CRM_SmashPig_ContextWrapper::createContext('cancelInactives', $processor);
        $provider = PaymentProviderFactory::getDefaultProvider();
        $provider->cancelSubscription(['subscr_id' => $inactive['trxn_id']]);
      }
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $inactive['id'])
        ->setValues([
          'contribution_status_id:name' => 'Cancelled',
          'cancel_date' => date('Y-m-d H:i:s', strtotime('now')),
          'cancel_reason' => 'Automatically cancelled for inactivity'
        ])->execute();
      $result[] = [
        'id' => $inactive['id'],
        'start_date' => $inactive['start_date'],
        'next_sched_contribution_date' => $inactive['next_sched_contribution_date'],
        'contact_id' => $inactive['contact_id']
      ];
    }
  }
}

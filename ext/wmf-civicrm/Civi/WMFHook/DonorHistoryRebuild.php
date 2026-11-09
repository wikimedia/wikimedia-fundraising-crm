<?php

namespace Civi\WMFHook;

use Civi\Api4\Action\WMFDonor\RebuildHistory;
use Civi\Api4\WMFDonorHistory;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Event\PostEvent;
use Civi\Core\Event\PreEvent;
use Civi\Core\Service\AutoSubscriber;

/**
 * Queues rebuilding wmf_donor_history when the live history may be wrong.
 *
 * The history trigger logs changes as they're made, which differs after a
 * contact merge, a refund, a gift received earlier than an existing history
 * change, a FY rollover or a new recurring alongside its first donation.
 */
class DonorHistoryRebuild extends AutoSubscriber {

  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_merge' => 'onMerge',
      'hook_civicrm_post::Contact' => 'onContactPost',
      'hook_civicrm_pre::Contribution' => 'onContributionPre',
      'hook_civicrm_pre::ContributionRecur' => 'onContributionRecurPre',
    ];
  }

  /**
   * Leave the merged contact's history rows on that contact.
   *
   * The old contact doesn't really need them, but might as well keep them for
   * troubleshooting as they'll be deleted in a year anyway.
   *
   * @param \Civi\Core\Event\GenericHookEvent $event
   */
  public function onMerge(GenericHookEvent $event): void {
    if ($event->type === 'cidRefs') {
      unset($event->data['wmf_donor_history']);
    }
  }

  /**
   * Queue rebuilding the merged to contact's history.
   *
   * This will now include the combined donation history of both contacts.
   *
   * @param \Civi\Core\Event\PostEvent $event
   */
  public function onContactPost(PostEvent $event): void {
    if ($event->action === 'merge') {
      $this->queueRebuild($event->id);
    }
  }

  /**
   * Queue rebuilding history from a contribution
   *
   * 1) from a refunded or un-refunded gift
   * 2) with a receive_date before the last FY rollover
   * 3) with a receive_date before a more recent history row
   *
   * @param \Civi\Core\Event\PreEvent $event
   *
   * @throws \CRM_Core_Exception
   */
  public function onContributionPre(PreEvent $event): void {
    if ($event->action === 'edit') {
      $before = $event->getValue('prevContribution');
      // Edits might leave out the amount.
      $amount = $event->hasValue('total_amount') ? $event->getValue('total_amount') : $before->total_amount;
      // Refunded, or completed again, e.g. a reversed chargeback.
      if ($this->isCompletedGift($before->contribution_status_id, $before->trxn_id, $before->total_amount)
        !== $this->isCompletedGift($event->getValue('contribution_status_id'), $event->getValue('trxn_id'), $amount)) {
        $this->queueRebuild($before->contact_id, $before->receive_date);
      }
    }
    elseif ($event->action === 'create' && $this->isCompletedGift($event->getValue('contribution_status_id'), $event->getValue('trxn_id'), $event->getValue('total_amount'))) {
      // Params can be in any strtotime() format, e.g. YmdHis from APIv4.
      $receiveDate = date('Y-m-d H:i:s', strtotime($event->getValue('receive_date')));
      $contactID = $event->getValue('contact_id');
      $financialYearStart = (date('n') < 7 ? date('Y') - 1 : date('Y')) . '-07-01 00:00:00';
      // It is before the most recent FY rollover.
      if ($receiveDate < $financialYearStart) {
        $this->queueRebuild($contactID, $receiveDate);
      }
      // Don't do anything if within merge window: it is very unlikely to change
      // history and if there is a recurring in play, we handle from the
      // recurring hook. If older, check if there's another history row after
      // this contribution (or one it should merge with).
      elseif ($receiveDate < date('Y-m-d H:i:s', time() - RebuildHistory::MERGE_SECONDS)
        && WMFDonorHistory::get(FALSE)
          ->addWhere('entity_id', '=', $contactID)
          ->addWhere('log_date', '>', date('Y-m-d H:i:s', strtotime($receiveDate) - RebuildHistory::MERGE_SECONDS))
          ->setLimit(1)
          ->execute()->count()) {
        $this->queueRebuild($contactID, $receiveDate);
      }
    }
  }

  /**
   * Queue rebuilding history from a new recurring, to merge it with its first gift.
   *
   * Or with a change just before, e.g. the gift it was post-payment converted from.
   *
   * @param \Civi\Core\Event\PreEvent $event
   */
  public function onContributionRecurPre(PreEvent $event): void {
    if ($event->action === 'create') {
      $this->queueRebuild($event->getValue('contact_id'), date('Y-m-d H:i:s'));
    }
  }

  /**
   * Is the contribution a completed gift, which the history counts, as in CalculatedData.
   *
   * @param int|string|null $statusID
   * @param string|null $trxnID
   * @param float|string|null $amount
   *
   * @return bool
   */
  private function isCompletedGift($statusID, ?string $trxnID, $amount): bool {
    return (int) $statusID === (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed')
      && !str_starts_with($trxnID ?? '', 'RFD ')
      && (float) $amount > 0;
  }

  /**
   * Queue rebuilding the contact's history.
   *
   * Rebuilds from a date run after the merge window, so related changes should
   * all be processed. Full rebuilds, after a contact merge, run straight away
   * from their own queue, as they'd otherwise wait behind delayed ones.
   *
   * @param int $contactID
   * @param string|null $startDate
   */
  private function queueRebuild(int $contactID, ?string $startDate = NULL): void {
    \Civi::queue($startDate ? 'wmf_donor_history' : 'wmf_donor_history_merge', [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 3,
      'retry_interval' => 300,
      'error' => 'abort',
    ])->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
      ['WMFDonor', 'rebuildHistory', ['contactIds' => [$contactID], 'startDate' => $startDate, 'checkPermissions' => FALSE]],
      'Rebuild donor history for contact ' . $contactID
    ), ['release_time' => $startDate ? \CRM_Utils_Time::time() + RebuildHistory::MERGE_SECONDS : NULL]);
  }

}

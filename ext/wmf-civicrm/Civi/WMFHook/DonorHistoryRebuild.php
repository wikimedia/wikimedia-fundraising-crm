<?php

namespace Civi\WMFHook;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Event\PostEvent;
use Civi\Core\Service\AutoSubscriber;

/**
 * Corrects wmf_donor_history when contacts are merged.
 */
class DonorHistoryRebuild extends AutoSubscriber {

  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_merge' => 'onMerge',
      'hook_civicrm_post::Contact' => 'onContactPost',
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
      \Civi::queue('wmf_donor_history', [
        'type' => 'Sql',
        'runner' => 'task',
        'retry_limit' => 3,
        'retry_interval' => 300,
        'error' => 'abort',
      ])->createItem(new \CRM_Queue_Task('civicrm_api4_queue',
        ['WMFDonor', 'rebuildHistory', ['contactIds' => [$event->id], 'checkPermissions' => FALSE]],
        'Rebuild donor history for contact ' . $event->id
      ));
    }
  }

}

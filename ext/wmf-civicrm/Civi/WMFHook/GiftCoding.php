<?php

namespace Civi\WMFHook;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\MailingProviderData;
use Civi\Api4\RelationshipCache;
use Civi\Core\Event\PreEvent;
use Civi\WMFHelper\ContributionSoft as ContributionSoftHelper;
use Civi\WMFHelper\Email;

class GiftCoding {

  /**
   * Channels whose donations are checked for activities.
   */
  private const OFFLINE_CHANNELS = ['Direct Mail', 'Direct Mail Upload', 'Other Offline'];

  /**
   * Gift types that don't get an appeal from having a relationship manager.
   */
  private const RELATIONSHIP_MANAGER_EXCLUDED_GIFT_TYPES = ['Matching Gift', 'Payroll Deduction'];

  /**
   * Implements hook_civicrm_pre::Contribution.
   *
   * If the donation has a replaceable appeal, set the appeal from the most
   * recent MG Engagement or Direct Mail Activity or DAF mailing for the donor
   * or a related contact or, failing that, the donor's relationship manager.
   * Also complete any scheduled MG Engagement activities.
   *
   * @throws \CRM_Core_Exception
   */
  public static function contributionPre(PreEvent $event): void {
    if ($event->action !== 'create' || self::isRepeatRecurringFinancialType((int) $event->getValue('financial_type_id'))) {
      return;
    }
    self::setAppealFromActivitiesAndMailings($event);
    self::setAppealFromRelationshipManager($event);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function setAppealFromActivitiesAndMailings(PreEvent $event): void {
    $channel = $event->getValue('Gift_Data.Channel');
    $giftType = $event->getValue('Gift_Data.Campaign');
    if (!self::isGiftCodingApplicable($channel, $giftType)) {
      return;
    }
    $contactID = (int) $event->getValue('contact_id');
    $contactIDs = self::getContactAndRelatedContactIDs($contactID);
    $receiveDate = $event->getValue('receive_date');
    // If channel is Other Offline / Direct Mail, get & complete activities
    if (in_array($channel, self::OFFLINE_CHANNELS, TRUE)) {
      $activities = self::getRecentMGActivities($contactIDs, $receiveDate);
      self::completeMGEngagementActivities($activities, [
        'contact_id' => $contactID,
        'total_amount' => $event->getValue('total_amount'),
        'currency' => $event->getValue('currency'),
        'Gift_Data.Campaign' => $giftType,
      ]);
    }
    // If the appeal is not replaceable, we change nothing
    if (!self::isAppealReplaceable($event->getValue('Gift_Data.Appeal'))) {
      return;
    }
    if ($giftType === 'Donor Advised Fund') {
      $mailings = self::getRecentDAFMailings($contactIDs, $receiveDate);
    }
    $event->mergeValues(self::getAppealValues($activities ?? [], $mailings ?? []));
  }

  /**
   * Set MGGO plus the year of the donation if the donor has a relationship
   * manager and the appeal wasn't set from an activity or mailing.
   *
   * @throws \CRM_Core_Exception
   */
  private static function setAppealFromRelationshipManager(PreEvent $event): void {
    if (
      !self::isAppealReplaceable($event->getValue('Gift_Data.Appeal'))
      || in_array($event->getValue('Gift_Data.Campaign'), self::RELATIONSHIP_MANAGER_EXCLUDED_GIFT_TYPES, TRUE)
    ) {
      return;
    }
    $contactID = (int) $event->getValue('contact_id');
    $relationshipManager = Contact::get(FALSE)
      ->addSelect('Prospect.Relationship_Manager')
      ->addWhere('id', '=', $contactID)
      ->execute()->first()['Prospect.Relationship_Manager'] ?? NULL;
    if ($relationshipManager) {
      $event->mergeValues([
        'Gift_Data.Appeal' => 'MGGO' . date('y', strtotime($event->getValue('receive_date'))),
        'Appeal_Change_Reason.Change_Reason' => 'Relationship Manager',
        'Appeal_Change_Reason.Entity_Table' => 'civicrm_contact',
        'Appeal_Change_Reason.Entity_ID' => $contactID,
      ]);
    }
  }

  /**
   * Implements hook_civicrm_pre::ContributionSoft.
   *
   * ContributionSoft::pre creates DAF relationships from DAF soft credits,
   * which don't exist yet when contributionPre runs, so re-run the checks for
   * the donor here to pick up the newly related contacts.
   *
   * @throws \CRM_Core_Exception
   */
  public static function contributionSoftPre(PreEvent $event): void {
    // We only need to continue if this is a DAF soft credit, because that's the only one we create relationship for
    if (
      $event->action !== 'create'
      || !in_array((int) $event->getValue('soft_credit_type_id'), ContributionSoftHelper::getDonorAdvisedFundSoftCreditTypes(), TRUE)
    ) {
      return;
    }
    $contributionID = (int) $event->getValue('contribution_id');
    $contribution = Contribution::get(FALSE)
      ->addSelect('contact_id', 'receive_date', 'total_amount', 'currency', 'Gift_Data.Channel', 'Gift_Data.Campaign', 'Gift_Data.Appeal', 'Gift_Data.Package', 'Appeal_Change_Reason.Change_Reason', 'Appeal_Change_Reason.Entity_Table', 'Appeal_Change_Reason.Entity_ID')
      ->addWhere('id', '=', $contributionID)
      ->execute()->first();
    if (!self::isGiftCodingApplicable($contribution['Gift_Data.Channel'], $contribution['Gift_Data.Campaign'])) {
      return;
    }
    $contactIDs = self::getContactAndRelatedContactIDs($contribution['contact_id']);
    // Also include the MG Engagement activity we set the appeal from
    // (and completed) in ContributionPre, if any.
    if ($contribution['Appeal_Change_Reason.Change_Reason'] === 'MG Engagement') {
      $completedMGEngagementID = $contribution['Appeal_Change_Reason.Entity_ID'];
    }
    // Do the same as we did in ContributionPre above
    if (in_array($contribution['Gift_Data.Channel'], self::OFFLINE_CHANNELS, TRUE)) {
      $activities = self::getRecentMGActivities($contactIDs, $contribution['receive_date'], $completedMGEngagementID ?? NULL);
      self::completeMGEngagementActivities($activities, $contribution);
    }
    // We only update if appeal is replaceable or we set the appeal in ContributionPre
    if (
      !self::isAppealReplaceable($contribution['Gift_Data.Appeal'])
      && empty($contribution['Appeal_Change_Reason.Change_Reason'])
    ) {
      return;
    }
    if ($contribution['Gift_Data.Campaign'] === 'Donor Advised Fund') {
      $mailings = self::getRecentDAFMailings($contactIDs, $contribution['receive_date']);
    }
    $values = self::getAppealValues($activities ?? [], $mailings ?? []);
    if (array_diff_assoc($values, $contribution)) {
      Contribution::update(FALSE)
        ->addWhere('id', '=', $contributionID)
        ->setValues($values)
        ->execute();
    }
    // TODO: if we updated the appeal, re-run isMajorGift()
    // but we would need to consider if the change can still be made at this point, since we might be
    // dealing with a soft credit & relationship added after the fact via the UI
  }

  /**
   * Direct Mail & Other Offline donations are checked for activities, DAF
   * donations for DAF mailings.
   */
  private static function isGiftCodingApplicable(?string $channel, ?string $giftType): bool {
    return in_array($channel, self::OFFLINE_CHANNELS, TRUE) || $giftType === 'Donor Advised Fund';
  }

  private static function isAppealReplaceable(?string $appeal): bool {
    return in_array($appeal, [NULL, ''], TRUE) || in_array($appeal, \Civi::settings()->get('wmf_replaceable_appeals'), TRUE);
  }

  private static function isRepeatRecurringFinancialType(int $financialTypeID): bool {
    $financialType = \CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'financial_type_id', $financialTypeID);
    return $financialType === 'Recurring Gift - Cash';
  }

  /**
   * Get the contact and contacts related to them with relationship types per
   * setting.
   *
   * @throws \CRM_Core_Exception
   */
  protected static function getContactAndRelatedContactIDs(int $contactID): array {
    $relatedContactIDs = RelationshipCache::get(FALSE)
      ->addSelect('far_contact_id')
      ->addWhere('near_contact_id', '=', $contactID)
      ->addWhere('near_relation:name', 'IN', \Civi::settings()->get('wmf_relationship_types_for_donation_attribution'))
      ->execute()->column('far_contact_id');
    return array_merge([$contactID], $relatedContactIDs);
  }

  /**
   * Mark the MG Engagement activities completed and notify their source
   * contacts of the donation.
   *
   * @param array $activities
   * @param array $donation
   *   contact_id, total_amount, currency & Gift_Data.Campaign (Gift Type) of the donation.
   *
   * @throws \CRM_Core_Exception
   */
  protected static function completeMGEngagementActivities(array $activities, array $donation): void {
    foreach ($activities as $activity) {
      if ($activity['activity_type_id:name'] === 'Major Gifts Engagement' && $activity['status_id:name'] === 'Scheduled') {
        Activity::update(FALSE)
          ->addValue('status_id:name', 'Completed')
          ->addWhere('id', '=', $activity['id'])
          ->execute();
        self::sendMGEngagementCompletionNotification($activity, $donation);
      }
    }
  }

  /**
   * Get appeal values from the most recent MG Engagement activity or Direct
   * Mail activity with an appeal or, failing that, the most recent DAF
   * mailing.
   *
   * MG Engagement activities without an appeal get MGGO plus the year of the
   * activity.
   *
   * @param array $activities
   *   Most recent first.
   * @param array $mailings
   *   Most recent first.
   *
   * @throws \CRM_Core_Exception
   */
  protected static function getAppealValues(array $activities, array $mailings): array {
    foreach ($activities as $activity) {
      if ($activity['activity_type_id:name'] === 'Major Gifts Engagement') {
        $appeal = $activity['Major_Gifts_Engagement.Appeal'];
        if (!$appeal) {
          $appeal = 'MGGO' . date('y', strtotime($activity['activity_date_time']));
        }
        return [
          'Gift_Data.Appeal' => $appeal,
          'Appeal_Change_Reason.Change_Reason' => 'MG Engagement',
          'Appeal_Change_Reason.Entity_Table' => 'civicrm_activity',
          'Appeal_Change_Reason.Entity_ID' => $activity['id'],
        ];
      }
      if ($activity['direct_mail_data.direct_mail_appeal']) {
        return [
          'Gift_Data.Appeal' => $activity['direct_mail_data.direct_mail_appeal'],
          'Gift_Data.Package' => $activity['direct_mail_data.direct_mail_package'],
          'Appeal_Change_Reason.Change_Reason' => 'Direct Mail',
          'Appeal_Change_Reason.Entity_Table' => 'civicrm_activity',
          'Appeal_Change_Reason.Entity_ID' => $activity['id'],
        ];
      }
    }
    if ($mailings) {
      return [
        'Gift_Data.Appeal' => $mailings[0]['mailing_identifier.Mailing_Appeal.Appeal'],
        'Appeal_Change_Reason.Change_Reason' => 'DAF Email',
        'Appeal_Change_Reason.Entity_Table' => 'civicrm_mailing',
        'Appeal_Change_Reason.Entity_ID' => $mailings[0]['mailing_identifier.id'],
      ];
    }
    return [];
  }

  /**
   * The 90 days before the donation, up to the end of the donation day.
   */
  private static function getAttributionWindow(string $receiveDate): array {
    $timestamp = strtotime($receiveDate);
    return [
      date('Y-m-d 00:00:00', strtotime('-90 days', $timestamp)),
      date('Y-m-d 23:59:59', $timestamp),
    ];
  }

  /**
   * Get Direct Mail & Major Gifts Engagement activities for contacts in the
   * 90 days before the donation.
   *
   * @throws \CRM_Core_Exception
   */
  protected static function getRecentMGActivities(array $contactIDs, string $receiveDate, ?int $completedMGEngagementID = NULL): array {
    return Activity::get(FALSE)
      ->addWhere('target_contact_id', 'CONTAINS ONE OF', $contactIDs)
      ->addWhere('activity_date_time', 'BETWEEN', self::getAttributionWindow($receiveDate))
      ->addClause('OR',
        ['AND', [['activity_type_id:name', '=', 'Direct Mail'], ['status_id:name', '=', 'Completed']]],
        ['AND', [['activity_type_id:name', '=', 'Direct Mail Upload'], ['status_id:name', '=', 'Completed']]],
        ['AND', [
          ['activity_type_id:name', '=', 'Major Gifts Engagement'],
          // If there is no $completedMGEngagementID, we are searching OR id is NULL, which is never true
          ['OR', [['status_id:name', '=', 'Scheduled'], ['id', '=', $completedMGEngagementID]]],
        ]]
      )
      ->addSelect(
        'activity_type_id:name',
        'status_id:name',
        'activity_date_time',
        'direct_mail_data.direct_mail_package',
        'direct_mail_data.direct_mail_appeal',
        'Major_Gifts_Engagement.Appeal',
        'Major_Gifts_Engagement.Expected_Donation',
        'source_contact_id',
        'target_contact_id',
      )
      ->addOrderBy('activity_date_time', 'DESC')
      ->execute()->getArrayCopy();
  }

  /**
   * Get the most recent DAF Mailing for contacts where the mailing appeal contains
   * 'DAF' with a send, open or click in the 90 days before the donation.
   *
   * @throws \CRM_Core_Exception
   */
  protected static function getRecentDAFMailings(array $contactIDs, string $receiveDate): array {
    return MailingProviderData::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDs)
      ->addWhere('event_type', 'IN', ['Sent', 'Clickstream', 'Click Through', 'Open'])
      ->addWhere('recipient_action_datetime', 'BETWEEN', self::getAttributionWindow($receiveDate))
      ->addWhere('mailing_identifier.Mailing_Appeal.Appeal', 'REGEXP BINARY', 'DAF')
      ->addSelect('mailing_identifier.id', 'mailing_identifier.Mailing_Appeal.Appeal')
      ->addOrderBy('recipient_action_datetime', 'DESC')
      ->setLimit(1)
      ->execute()->getArrayCopy();
  }

  /**
   * Notify the MG Engagement activity's source contact of the donation.
   *
   * @throws \CRM_Core_Exception
   */
  private static function sendMGEngagementCompletionNotification(array $activity, array $donation): void {
    $to = Email::getStaffNotificationEmail($activity['source_contact_id']);

    $formattedAmount = \Civi::format()->money($donation['total_amount'], $donation['currency']);
    $giftType = \CRM_Core_PseudoConstant::getLabel('CRM_Contribute_BAO_Contribution', 'Gift_Data.Campaign', $donation['Gift_Data.Campaign']);
    // Just get the first contact if there is more than one target.
    $targetContactID = $activity['target_contact_id'][0];
    $targetContactName = Contact::get(FALSE)
      ->addWhere('id', '=', $targetContactID)
      ->addSelect('display_name')
      ->execute()->first()['display_name'] ?? NULL;

    $activityLink = \CRM_Utils_System::url('civicrm/activity', [
      'action' => 'view',
      'id' => $activity['id'],
      'cid' => $targetContactID,
      'reset' => 1,
    ], TRUE, NULL, FALSE);
    $targetContactLink = \CRM_Utils_System::url('civicrm/contact/view', [
      'cid' => $targetContactID,
      'reset' => 1,
    ], TRUE, NULL, FALSE);
    $contributionsLink = \CRM_Utils_System::url('civicrm/contact/view', [
      'cid' => $donation['contact_id'],
      'selectedChild' => 'contribute',
      'reset' => 1,
    ], TRUE, NULL, FALSE);

    // If the donation is from a different contact than the activity, add the name of the donor
    $donorName = $targetContactName;
    $fromClause = '';
    if ($donation['contact_id'] !== $targetContactID) {
      $donorLink = \CRM_Utils_System::url('civicrm/contact/view', [
        'cid' => $donation['contact_id'],
        'reset' => 1,
      ], TRUE, NULL, FALSE);
      $donorName = Contact::get(FALSE)
        ->addWhere('id', '=', $donation['contact_id'])
        ->addSelect('display_name')
        ->execute()->first()['display_name'] ?? NULL;
      $fromClause = " from <a href='{$donorLink}'>" . htmlspecialchars($donorName ?? '') . '</a>';
    }

    $expectedClause = '';
    if ($activity['Major_Gifts_Engagement.Expected_Donation']) {
      $formattedExpectedAmount = \CRM_Utils_Money::format($activity['Major_Gifts_Engagement.Expected_Donation'], $donation['currency'], '%c%a');
      $expectedClause = " (expected {$formattedExpectedAmount})";
    }

    $message = "<p>Your <a href='{$activityLink}'>MG Engagement activity</a> for "
      . "<a href='{$targetContactLink}'>" . htmlspecialchars($targetContactName ?? '') . '</a> has been completed '
      . "with a donation of <a href='{$contributionsLink}'>{$formattedAmount}</a>{$expectedClause} of type "
      . "{$giftType}{$fromClause}.</p>";

    \Civi::queue('email', [
      'type' => 'Sql',
      'runner' => 'task',
      'retry_limit' => 3,
      'retry_interval' => 20,
      'error' => 'abort',
    ])->createItem(new \CRM_Queue_Task(
      [self::class, 'sendEmail'],
      [[
        'toEmail' => $to,
        'from' => 'MG Engagement Bot <fr-tech+mg_engagement@wikimedia.org>',
        'subject' => "Donation from {$donorName} for {$formattedAmount}",
        'html' => $message,
      ]],
      'Send MG Engagement completion notification for activity ' . $activity['id']
    ), ['weight' => 100]);
  }

  public static function sendEmail(\CRM_Queue_TaskContext $taskContext, array $params): bool {
    return \CRM_Utils_Mail::send($params);
  }

}

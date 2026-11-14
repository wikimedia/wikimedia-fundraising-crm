<?php

namespace Civi\WMFHook;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionSoft;
use Civi\Core\Event\PostEvent;
use Civi\Core\Event\PreEvent;
use Civi\WMFHelper\ContributionSoft as ContributionSoftHelper;

class GLCode {

  /**
   * Contribution fields that getGLCode() reads.
   */
  public const array INPUT_FIELDS = [
    'Gift_Data.Appeal',
    'Gift_Data.Channel',
    'Gift_Data.Fund',
    'Gift_Data.Campaign',
    'total_amount',
    'contribution_recur_id',
    'contact_id',
    'receive_date',
  ];

  /**
   * Implements hook_civicrm_pre::Contribution.
   *
   * Set the GL code on create, and recalculate it on edits that change any
   * of the input fields.
   *
   * @throws \CRM_Core_Exception
   */
  public static function contributionPre(PreEvent $event): void {
    if ($event->action === 'create') {
      // Refund reversals copy the GL code of the contribution they reverse.
      if ($event->getValue('contribution_extra.parent_contribution_id') && $event->getValue('Gift_Data.gl_code')) {
        return;
      }
      $contribution = ['Gift_Data.gl_code' => NULL];
    }
    elseif ($event->action === 'edit' && self::hasInputValue($event)) {
      $contribution = self::getContribution($event->id);
      if (!self::hasInputChange($event, $contribution)) {
        return;
      }
    }
    else {
      return;
    }
    // Build $contribution with the new values, not existing (if editing)
    foreach (self::INPUT_FIELDS as $field) {
      if ($event->hasValue($field)) {
        $contribution[$field] = $event->getValue($field);
      }
    }
    $glCode = self::getGLCode($contribution);
    if ($glCode !== $contribution['Gift_Data.gl_code']) {
      $event->setValue('Gift_Data.gl_code', $glCode);
    }
  }

  /**
   * Implements hook_civicrm_post::ContributionSoft.
   *
   * Recalculate the GL code when a DAF soft credit is added for a contact
   * with a relationship manager, as soft credits are added after the
   * contribution. We do not handle soft credit edit or delete, because these
   * are rare.
   *
   * @throws \CRM_Core_Exception
   */
  public static function contributionSoftPost(PostEvent $event): void {
    if (
      $event->action !== 'create'
      || !in_array((int) $event->getValue('soft_credit_type_id'), ContributionSoftHelper::getDonorAdvisedFundSoftCreditTypes(), TRUE)
      || !self::hasRelationshipManager((int) $event->getValue('contact_id'))
    ) {
      return;
    }
    $contribution = self::getContribution((int) $event->getValue('contribution_id'));
    $glCode = self::getGLCode($contribution);
    if ($glCode !== $contribution['Gift_Data.gl_code']) {
      Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('Gift_Data.gl_code', $glCode)
        ->execute();
    }
  }

  private static function hasInputValue(PreEvent $event): bool {
    foreach (self::INPUT_FIELDS as $field) {
      if ($event->hasValue($field)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * We only recalculate if one of the input fields changed.
   */
  private static function hasInputChange(PreEvent $event, array $contribution): bool {
    foreach (self::INPUT_FIELDS as $field) {
      if (!$event->hasValue($field)) {
        continue;
      }
      if ($field === 'receive_date') {
        // The params may hold YmdHis rather than Y-m-d H:i:s.
        $isChanged = strtotime((string) $event->getValue($field)) !== strtotime((string) $contribution[$field]);
      }
      else {
        $isChanged = $event->getValue($field) != $contribution[$field];
      }
      if ($isChanged) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function getContribution(int $contributionID): array {
    return Contribution::get(FALSE)
      ->setSelect(['id', ...self::INPUT_FIELDS, 'Gift_Data.gl_code'])
      ->addWhere('id', '=', $contributionID)
      ->execute()->single();
  }

  /**
   * Get the GL code from MG attribution rules, falling back to the
   * channel's GL code.
   *
   * @param array $contribution
   *   Values for INPUT_FIELDS, in APIv4 format, plus the id of an existing
   *   contribution.
   *
   * @throws \CRM_Core_Exception
   */
  public static function getGLCode(array $contribution): ?string {
    $channel = $contribution['Gift_Data.Channel'] ?? '';
    $channelGLCode = \CRM_Core_OptionGroup::values('Gift_Data_Channel', labelColumnName: 'description', onlyActive: FALSE)[$channel] ?? NULL;
    if (!$channelGLCode) {
      self::alertNoChannel($contribution);
      return NULL;
    }
    $amount = $contribution['total_amount'];
    $appeal = $contribution['Gift_Data.Appeal'] ?? '';
    [$hasRelationshipManager, $isMajorGiftsAssociated] = self::getMajorGiftsAssociation($contribution);
    $isDAF = ($contribution['Gift_Data.Campaign'] ?? '') === 'Donor Advised Fund';

    if (str_starts_with($contribution['Gift_Data.Fund'] ?? '', 'Restricted')) {
      return 'GL43428';
    }
    if ($channel === 'Chapter Gifts') {
      return $channelGLCode;
    }
    if ($hasRelationshipManager) {
      return 'GL43485';
    }
    if ($amount >= 10000 && $isMajorGiftsAssociated) {
      return 'GL43485';
    }
    if ($channel === 'Workplace Giving') {
      return $channelGLCode;
    }
    if ($channel === 'Recurring Gift') {
      if ($amount >= 10000) {
        return 'GL43485';
      }
      if ($amount >= 84) {
        $frequencyUnit = self::getFrequencyUnit((int) ($contribution['contribution_recur_id'] ?? 0));
        if ($frequencyUnit === 'month' || ($frequencyUnit === 'year' && $amount >= 1000)) {
          return 'GL43487';
        }
      }
      return $channelGLCode;
    }
    if ($isDAF) {
      // The case where the donor themselves has a relationship manager is already covered above
      switch (true) {
        case $amount >= 10000:
        case isset($contribution['id']) && self::hasDAFHolderWithRelationshipManager($contribution['id']):
          return 'GL43485';
        case $amount >= 1000 || $isMajorGiftsAssociated:
          return 'GL43486';
        default:
          return $channelGLCode;
      }
    }
    if ($appeal === 'White Mail' || str_ends_with($appeal, 'WM') || $channel === 'Other Offline') {
      switch (true) {
        case $amount >= 10000:
          return 'GL43485';
        case $amount >= 1000 || $isMajorGiftsAssociated:
          return 'GL43489';
        default:
          return $channelGLCode;
      }
    }
    if ($isMajorGiftsAssociated) {
      return 'GL43487';
    }
    return $channelGLCode;
  }

  /**
   * Send failmail, as a contribution without a GL code will mess up accounting.
   */
  private static function alertNoChannel(array $contribution): void {
    \Civi::log('wmf')->alert(
      'channel: No channel or no GL Code for channel on contribution {contribution_id} for contact {contact_id}',
      [
        'channel' => $contribution['Gift_Data.Channel'] ?? '',
        'contribution_id' => $contribution['id'] ?? 'being created',
        'contact_id' => $contribution['contact_id'],
        'subject' => 'No channel or no GL Code for channel',
      ]
    );
  }

  /**
   * Does the donor have a relationship manager, and is the donation associated
   * with major gifts: an MG appeal, or a major, mid-value plus or mid-value
   * donor segment just before it was received?
   *
   * This would be cleaner in two functions, but we can make it one query here.
   * There might be some advantage to caching this but only for contacts like
   * employers who might have many contributions in one import. But if we do
   * cache, we would need to make sure that the donor segment isn't changing
   * or that it didn't matter if it did. Or we could cache by contact id and
   * receive date (because many are repeated dates), but that's more complexity
   * with not that much benefit.
   *
   * @param array $contribution
   *   contact_id, receive_date and Gift_Data.Appeal, in APIv4 format.
   *
   * @return bool[]
   *   [hasRelationshipManager, isMajorGiftsAssociated]
   *
   * @throws \CRM_Core_Exception
   */
  public static function getMajorGiftsAssociation(array $contribution): array {
    $isMGAppeal = str_contains($contribution['Gift_Data.Appeal'] ?? '', 'MG');
    $isMGChannel = (in_array($contribution['Gift_Data.Channel'] ?? '', ['Direct Solicitation', 'Planned Giving', 'Events'], TRUE));
    // Contacts like Anonymous pool many donors' gifts, so ignore
    // their relationship manager and segment.
    if (in_array((int) $contribution['contact_id'], GiftCoding::getExcludedContactIDs(), TRUE)) {
      return [FALSE, $isMGAppeal || $isMGChannel];
    }
    $contact = Contact::get(FALSE)
      ->addSelect('Prospect.Relationship_Manager', 'history.donor_segment_overall')
      ->addJoin('WMFDonorHistory AS history', 'LEFT',
        ['history.entity_id', '=', 'id'],
        ['history.log_date', '<', '"' . $contribution['receive_date'] . '"']
      )
      ->addWhere('id', '=', $contribution['contact_id'])
      ->addOrderBy('history.log_date', 'DESC')
      ->addOrderBy('history.log_id', 'DESC')
      ->setLimit(1)
      ->execute()->first();
    $isMGSegment = ($contact['history.donor_segment_overall'] ?? 990) <= 300;
    return [!empty($contact['Prospect.Relationship_Manager']), $isMGAppeal || $isMGSegment || $isMGChannel];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function hasDAFHolderWithRelationshipManager(int $contributionID): bool {
    return (bool) ContributionSoft::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_id', '=', $contributionID)
      ->addWhere('soft_credit_type_id', 'IN', ContributionSoftHelper::getDonorAdvisedFundSoftCreditTypes())
      ->addWhere('contact_id.Prospect.Relationship_Manager', 'IS NOT EMPTY')
      ->execute()->count();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function hasRelationshipManager(int $contactID): bool {
    return !empty(Contact::get(FALSE)
      ->addSelect('Prospect.Relationship_Manager')
      ->addWhere('id', '=', $contactID)
      ->execute()->first()['Prospect.Relationship_Manager']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function getFrequencyUnit(int $contributionRecurID): ?string {
    if (!$contributionRecurID) {
      return NULL;
    }
    return ContributionRecur::get(FALSE)
      ->addSelect('frequency_unit')
      ->addWhere('id', '=', $contributionRecurID)
      ->execute()->first()['frequency_unit'] ?? NULL;
  }

}

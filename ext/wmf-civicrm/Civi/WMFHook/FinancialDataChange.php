<?php

namespace Civi\WMFHook;

use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\CustomValue;
use Civi\API\Event\PrepareEvent;
use Civi\Api4\Generic\AbstractSaveAction;
use Civi\Core\Event\PreEvent;
use Civi\WMFBatch\FinanceConstants;

class FinancialDataChange {

  /**
   * Implements hook_civicrm_pre().
   *
   * When a contribution's financial_type_id or Gift_Data.gl_code is
   * corrected after it has already been settled into a batch, record a
   * FinancialDataAdjustment so the correction can later be turned into a
   * reversal + recreation journal (see the GL Code & Financial Type
   * Correction Plan).
   */
  public static function pre(PreEvent $event): void {
    if ($event->action !== 'edit' || !$event->id) {
      return;
    }
    if (!$event->hasValue('financial_type_id') && !$event->hasValue('Gift_Data.gl_code')) {
      return;
    }
    $current = Contribution::get(FALSE)
      ->addWhere('id', '=', $event->id)
      ->addSelect('financial_type_id', 'Gift_Data.gl_code', 'contribution_settlement.settlement_batch_reference')
      ->execute()->single();

    $batchReference = $current['contribution_settlement.settlement_batch_reference'];

    $glCodeBefore = $current['Gift_Data.gl_code'];
    $glCodeAfter = $event->hasValue('Gift_Data.gl_code') ? $event->getValue('Gift_Data.gl_code') : $glCodeBefore;
    $isGlCodeChange = $event->hasValue('Gift_Data.gl_code') && !empty($glCodeAfter) && $glCodeAfter !== $glCodeBefore;

    $endowmentFinancialTypeID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Endowment Gift');
    $financialTypeIDBefore = (int) $current['financial_type_id'];
    $financialTypeIDAfter = $event->hasValue('financial_type_id') ? (int) $event->getValue('financial_type_id') : $financialTypeIDBefore;
    $isEndowmentBefore = $financialTypeIDBefore === $endowmentFinancialTypeID;
    $isEndowmentAfter = $financialTypeIDAfter === $endowmentFinancialTypeID;
    $isEndowmentChange = $financialTypeIDAfter !== $financialTypeIDBefore && ($isEndowmentBefore || $isEndowmentAfter);

    if ((!$isGlCodeChange && !$isEndowmentChange) || empty($batchReference)) {
      return;
    }

    $adjustment = [
      'entity_id' => $event->id,
      'gl_code_before' => $glCodeBefore,
      'gl_code_after' => $glCodeAfter,
      'is_endowment_before' => $isEndowmentBefore,
      'is_endowment_after' => $isEndowmentAfter,
    ];

    // Locking (rather than just checking the batch status) guards against
    // WMFAudit.GenerateBatch being mid-export for this batch right now -
    // without it we could read a stale "not yet Exported" status and skip
    // recording an adjustment for a journal that is about to exist.
    // A timeout of 1 (not 0) is used deliberately: CRM_Core_Lock::acquire()
    // treats a falsy timeout as "use the 3-second default", so 0 would not
    // give the quick, non-blocking attempt it looks like it would.
    $lock = \Civi::lockManager()->acquire(FinanceConstants::BATCH_LOCK_PREFIX . $batchReference);
    if (!$lock->isAcquired()) {
      self::saveAdjustment($adjustment + ['status' => 'needs_attention']);
      self::alertFrTech($event->id, $batchReference, 'could not acquire the settlement batch lock');
      return;
    }

    try {
      $batchStatus = Batch::get(FALSE)
        ->addWhere('name', '=', $batchReference)
        ->addSelect('status_id:name')
        ->execute()->first()['status_id:name'] ?? NULL;

      if ($batchStatus === 'Exported') {
        // original_batch is only populated once we know there is a real
        // journal to reverse - WMFAudit.reserveAdjustmentBatches treats a
        // null original_batch as "no adjustment journal needed".
        self::saveAdjustment($adjustment + [
          'status' => 'pending',
          'original_batch' => $batchReference,
        ]);
      }
      elseif ($batchStatus === 'Partially Exported') {
        self::saveAdjustment($adjustment + ['status' => 'needs_attention']);
        self::alertFrTech($event->id, $batchReference, 'batch is Partially Exported');
      }
      // Any other status means the batch hasn't been exported yet, so there
      // is no journal to reverse - the not-yet-exported batch will pick up
      // the corrected values when it is generated.
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Listener for civi.api.prepare.
   *
   * Custom fields can't default to the current time / user, so fill
   * created_date, created_id & modified_date on apiv4 FinancialDataAdjustment writes.
   */
  public static function apiPrepare(PrepareEvent $event): void {
    $action = $event->getActionName();
    if ($event->getEntityName() !== 'Custom_FinancialDataAdjustment' || !in_array($action, ['create', 'update', 'save'], TRUE)) {
      return;
    }
    $apiRequest = $event->getApiRequest();
    if ($apiRequest['version'] !== 4) {
      return;
    }
    $now = date('Y-m-d H:i:s');
    if ($action === 'save') {
      $records = $apiRequest->getRecords();
      foreach ($records as &$record) {
        $record += ['modified_date' => $now];
        if (empty($record['id']) && !self::isMatched($apiRequest, $record + $apiRequest->getDefaults())) {
          $record += self::getCreateDefaults($now);
        }
      }
      $apiRequest->setRecords($records);
      return;
    }
    $values = $apiRequest->getValues() + ['modified_date' => $now];
    if ($action === 'create') {
      $values += self::getCreateDefaults($now);
    }
    $apiRequest->setValues($values);
  }

  /**
   * Will save() match this record to an existing row?
   *
   * Core only resolves `match` per record after prepare, so repeat its lookup.
   * @see \Civi\Api4\Generic\Traits\MatchParamTrait::matchExisting()
   */
  private static function isMatched(AbstractSaveAction $apiRequest, array $record): bool {
    $match = $apiRequest->getMatch();
    if (!$match || array_diff($match, array_keys($record))) {
      return FALSE;
    }
    $where = [];
    foreach ($match as $key) {
      $where[] = ($record[$key] === '' || $record[$key] === NULL) ? [$key, 'IS EMPTY'] : [$key, '=', $record[$key]];
    }
    return civicrm_api4($apiRequest->getEntityName(), 'get', [
      'select' => ['id'],
      'where' => $where,
      'checkPermissions' => FALSE,
      'limit' => 2,
    ])->count() === 1;
  }

  private static function getCreateDefaults(string $now): array {
    return [
      'created_date' => $now,
      'created_id' => \CRM_Core_Session::getLoggedInContactID() ?? \CRM_Core_BAO_UFMatch::getContactId(1),
    ];
  }

  private static function saveAdjustment(array $values): void {
    CustomValue::create('FinancialDataAdjustment', FALSE)
      ->setValues($values)
      ->execute();
  }

  private static function alertFrTech(int $contributionID, string $batchReference, string $reason): void {
    \Civi::log('wmf')->alert(
      'financial_data_change: Contribution {contribution_id} GL/financial type correction needs review - {reason} (batch {batch_reference})',
      [
        'contribution_id' => $contributionID,
        'batch_reference' => $batchReference,
        'reason' => $reason,
        'subject' => 'Financial data adjustment needs review for contribution ' . $contributionID,
      ]
    );
  }

}

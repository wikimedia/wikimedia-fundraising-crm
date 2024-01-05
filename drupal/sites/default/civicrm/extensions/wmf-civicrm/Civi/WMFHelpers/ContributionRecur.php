<?php

namespace Civi\WMFHelpers;

use Civi\Api4\Contribution;
use CRM_Core_PseudoConstant;
use SmashPig\Core\Context;

class ContributionRecur {

  static $inactiveStatuses = NULL;

  /**
   * The financial type for recurring contributions is 'Recurring Gift' for the first one.
   *
   * @return int
   */
  public static function getFinancialTypeForFirstContribution(): int {
    return \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Recurring Gift');
  }

  /**
   * Is this the first contribution against the recurring contribution.
   *
   * ie are there no contributions .. yet
   * @param int $contributionRecurID
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function isFirst(int $contributionRecurID): bool {
    $existing = Contribution::get(FALSE)
      ->addWhere('contribution_recur_id', '=', $contributionRecurID)
      ->addSelect('id')->setLimit(1)->execute()->first()['id'] ?? NULL;
    return !$existing;
  }

  public static function getFinancialType(int $contributionRecurID): int {
    if (self::isFirst($contributionRecurID)) {
      return ContributionRecur::getFinancialTypeForFirstContribution();
    }
    return ContributionRecur::getFinancialTypeForSubsequentContributions();
  }

  /**
   * The financial type for recurring contributions is 'Recurring Gift - Cash' after the first one.
   *
   * @return int
   */
  public static function getFinancialTypeForSubsequentContributions(): int {
    return \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Recurring Gift - Cash');
  }

  /**
   * If recur record is in an 'inactive' status (currently defined as Completed,
   * Cancelled, or Failed), reactivate it.
   *
   * @param object $recur_record
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function reactivateIfInactive(object $recur_record): void {
    if (in_array($recur_record->contribution_status_id, self::getInactiveStatusIds())) {
      \Civi::log('wmf')->info("Reactivating contribution_recur with id '$recur_record->id'");
      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recur_record->id)
        ->setValues([
          'cancel_date' => NULL,
          'cancel_reason' => '',
          'end_date' => NULL,
          'contribution_status_id' => 'In Progress'
        ])->execute();
    }
  }

  protected static function getInactiveStatusIds(): array {
    if (self::$inactiveStatuses === NULL) {
      $statuses = [];
      foreach ( \CRM_Contribute_BAO_ContributionRecur::getInactiveStatuses() as $status) {
        $statuses[] = CRM_Core_PseudoConstant::getKey(
          'CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $status
        );
      }
      self::$inactiveStatuses = $statuses;
    }
    return self::$inactiveStatuses;
  }

  /**
   * If recur record is upgradable (non-upgradeable recurrings include PayPal and India's UPI)
   * return contribution_recur.id, contact_id, currency, amount, donor_name and next_sched_contribution_date
   *
   * @param int $contact_id
   * @param string $checksum
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function getUpgradeable(int $contact_id, string $checksum): array {
    $result = [];
    // check if valid checksum
    if (\CRM_Contact_BAO_Contact_Utils::validChecksum($contact_id, $checksum)) {
      \Civi::log('wmf')->debug("Donor '$contact_id' has valid checksum");
      $allContactIds = Contact::duplicateContactIds($contact_id);
      \Civi::log('wmf')->debug("Check if donor " . json_encode($allContactIds) . " upgradable");
      $contribution_recurs = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('id', 'currency', 'amount', 'next_sched_contribution_date', 'contact.display_name')
        ->addJoin('Contact AS contact', 'LEFT', ['contact.id', '=', $contact_id])
        ->addJoin('PaymentProcessor AS payment_processor', 'LEFT', ['payment_processor.id', '=', 'payment_processor_id'])
        ->addWhere('contact_id', 'IN', $allContactIds)
        ->addWhere('cancel_date', 'IS NULL')
        ->addWhere('next_sched_contribution_date', 'IS NOT NULL')
        // use adyen and ingenico first, find dlocal except upi later
        ->addWhere('payment_processor.name', 'IN', ['adyen', 'ingenico'])
        ->execute();
      // Also filter out multi recurring e.g. contact 1925710.
      if (count($contribution_recurs) === 1) {
        $result[] = [
          'id' => $contribution_recurs[0]['id'],
          'contact_id' => $contact_id,
          'currency' => $contribution_recurs[0]['currency'],
          'amount' => $contribution_recurs[0]['amount'],
          'donor_name' => $contribution_recurs[0]['contact.display_name'],
          'next_sched_contribution_date' => $contribution_recurs[0]['next_sched_contribution_date'],
        ];
      }
      else {
        \Civi::log('wmf')->info("Donor '$contact_id' has " . count($contribution_recurs) . " valid recurring");
      }
    }
    else {
      \Civi::log('wmf')->info("Donor '$contact_id' checksum not valid");
    }
    return $result;
  }

  public static function gatewayManagesOwnRecurringSchedule($gateway) : bool {
    wmf_common_create_smashpig_context('RecurHelper', $gateway);
    $config = Context::get()->getProviderConfiguration();
    $defaultMethod = $config->val('default-method');
    if (!$config->nodeExists("payment-provider/$defaultMethod/class")) {
      return FALSE;
    }
    return is_subclass_of(
      $config->val("payment-provider/$defaultMethod/class"),
      'SmashPig\PaymentProviders\IRecurringPaymentProfileProvider'
    );
  }
}

<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;

class OfflineAuditMessage extends AuditMessage {

  public bool $isLogUnavailableFields = TRUE;

  protected bool $isRestrictToSupportedFields = TRUE;

  /**
   * WMF Audit message incoming from our smashpig audit reconciliation framework.
   *
   * @var array{
   *    gateway: string,
   *    audit_file_gateway: string,
   *    is_daf: boolean,
   *    is_matching_gift: boolean,
   *    banking_institution: string,
   *    matching_gift_organization: string,
   *    donor_advised_fund_name: string,
   *    note: string,
   *    backend_processor: string,
   *    backend_processor_txn_id: string,
   *    backend_processor_parent_id: string,
   *    backend_processor_reversal_id: string,
   *    gateway_txn_id: string,
   *    payment_method: string,
   *    check_number: string,
   *    type: string,
   *    original_currency: string,
   *    settled_currency: string,
   *    settlement_batch_reference: string,
   *    settled_fee_amount: float,
   *    settled_net_amount: float,
   *    settled_total_amount: float,
   *    settled_matching_gift_total_amount: float,
   *    settled_matching_gift_fee_amount: float,
   *    settled_matching_gift_net_amount: float,
   *    settled_individual_gift_total_amount: float,
   *    settled_individual_gift_fee_amount: float,
   *    settled_individual_gift_net_amount: float,
   *    original_net_amount: float,
   *    original_fee_amount: float,
   *    original_total_amount: float,
   *    original_matching_gift_total_amount: float,
   *    original_matching_gift_fee_amount: float,
   *    original_matching_gift_net_amount: float,
   *    original_individual_gift_total_amount: float,
   *    original_individual_gift_fee_amount: float,
   *    original_individual_gift_net_amount: float,
   *    exchange_rate: float,
   *    settled_date: string,
   *    external_identifier: string,
   *    date: string,
   *    first_name: string,
   *    last_name: string,
   *    full_name: string,
   *    email: string,
   *    prefix: string,
   *    phone: string,
   *    country: string,
   *    postal_code: string,
   *    state_province: string,
   *    city: string,
   *    street_address: string,
   *    supplemental_address_1: string,
   *    partner_full_name: string,
   *    gift_source: string,
   *    }
   */
  protected array $message;

  public function normalize(): array {
    $message = parent::normalize();
    $message['check_number'] = $this->getCheckNumber();
    return $message;
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function getExistingContribution(): ?array {
    if ($this->hasMatchingOrganizationGift()) {
      // Check first that the matching gift exists. If not return NULL - the
      // row needs to be processed.
      $contribution = $this->lookupByMatchingBackendProcessorTrxnId();
      if (!$contribution || !$this->hasIndividualGift()) {
        return $contribution;
      }
      // If there is an organization gift but an individual gift is expected,
      // but not found, then let the parent find the individual gift.

    }
    return parent::getExistingContribution();
  }

  public function hasMatchingOrganizationGift(): bool {
    return !empty($this->message['original_matching_gift_total_amount']);
  }

  public function hasIndividualGift(): bool {
    return !empty($this->message['original_individual_gift_total_amount']);
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function lookupByMatchingBackendProcessorTrxnId(): ?array {
    $matchingGiftBackendProcessorTrxnId =  $this->getBackendProcessorTxnID() . '_MATCHED';
    return Contribution::get(FALSE)
      ->setSelect($this->getContributionSelectFields())
      ->addWhere('contribution_extra.backend_processor', '=', $this->getBackendProcessor())
      ->addWhere('contribution_extra.backend_processor_txn_id', '=', $matchingGiftBackendProcessorTrxnId)
      ->execute()->first();
  }

}

<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use SmashPig\PaymentProviders\PaymentProviderFactory;

/**
 * Looks up scheme transaction IDs for recurring records.
 *
 * @method setBatch(int $batch) set the number of rows to update
 */
class FillSchemeId extends AbstractAction {

  /**
   * @var int Number of rows to update in a single run
   */
  protected $batch = 1000;

  public function _run(Result $result) {
    \CRM_SmashPig_ContextWrapper::createContext('SchemeIdFiller', 'ingenico');
    $provider = PaymentProviderFactory::getProviderForMethod('cc');
    $contributionRecurs = ContributionRecur::get(FALSE)
      ->addSelect('MAX(contribution.contribution_extra.gateway_txn_id) AS max_txn_id')
      ->addJoin('Contribution AS contribution', 'LEFT')
      ->addGroupBy('id')
      ->addWhere('payment_processor_id:name', '=', 'ingenico')
      ->addWhere('contribution_status_id:name', '=', 'In Progress')
      ->addWhere('contribution_recur_smashpig.initial_scheme_transaction_id', 'IS NULL')
      ->addWhere('contribution.receive_date', '>', '-45 days')
      ->setLimit($this->batch)
      ->execute();

    foreach ($contributionRecurs as $recurRecord) {
      $id = $recurRecord['max_txn_id'];
      $paymentStatus = $provider->getPaymentStatus( $id );
      // Cheap trick, using a blank space placeholder to mark ones that we've tried and failed to get an ID for
      // Later we can replace those with null again.
      $schemeId = $paymentStatus['paymentOutput']['cardPaymentMethodSpecificOutput']['initialSchemeTransactionId'] ??
        $paymentStatus['paymentOutput']['cardPaymentMethodSpecificOutput']['schemeTransactionId'] ?? '';
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurRecord['id'])
        ->addValue('contribution_recur_smashpig.initial_scheme_transaction_id', $schemeId)
        ->execute();
      if ($this->debug) {
        $result[$id] = [
          'rawResponse' => $paymentStatus,
        ];
      } else {
        $result[$id] = $schemeId;
      }
    }
  }
}

<?php

namespace Civi\Api4\Action\ContributionRecur;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentToken;
use CRM_Core_Payment_SmashPig;
use CRM_Core_Payment_SmashPigRecurringProcessor;
use CRM_SmashPig_ContextWrapper;
use SmashPig\PaymentProviders\PaymentProviderFactory;

/**
 * Class UpdateToken.
 *
 * Looks up stored payment details based on a shopper reference stored in the
 * payment_token.token field, moves the shopper reference to the invoice_id
 * field on the contribution recur record, and sets the token field to the
 * stored token returned from the payment processor.
 *
 * @method $this setContributionRecurId(int $contributionRecurID) Set recurring ID.
 * @method int getContributionRecurId() Get recurring ID.
 * @method $this setProcessorName(string $processorName) Set processor name.
 * @method string getProcessorName() Get processor name.
 */
class UpdateToken extends AbstractAction {

  /**
   * @var int
   */
  protected $contributionRecurId;

  /**
   * @var string
   */
  protected $processorName;

  public function _run(Result $result) {
    $recurInfo = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $this->contributionRecurId)
      ->setSelect([
        'invoice_id',
        'is_test',
        'payment_token_id',
        'payment_token_id.token',
        'payment_processor_id.name'
      ])
      ->execute()
      ->first();
    // Sanity checks
    if ($recurInfo['payment_processor_id.name'] !== $this->processorName) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "processor name {$recurInfo['payment_processor_id.name']} instead of " .
        "expected {$this->processorName}."
      );
    }
    if ($recurInfo['invoice_id'] !== NULL) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "invoice_id {$recurInfo['invoice_id']} instead of expected null."
      );
    }
    $processorContactId = $recurInfo['payment_token_id.token'];
    if (!$processorContactId) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "no associated token - cannot update."
      );
    }
    CRM_SmashPig_ContextWrapper::createContext(
      "processor_{$this->processorName}", $this->processorName
    );
    // Look up and normalize the payment instrument corresponding to this
    // recurring contribution, since we don't seem to be actually populating
    // the contribution_recur.payment_instrument_id column
    $previousContribution = CRM_Core_Payment_SmashPigRecurringProcessor::getPreviousContribution(
      $recurInfo
    );
    $paymentMethod = CRM_Core_Payment_SmashPig::getPaymentMethod(
      $previousContribution
    );
    // Get a SmashPig provider instance for the method and look up the stored
    // tokens associated with the processor contact ID / ShopperReference
    $provider = PaymentProviderFactory::getProviderForMethod($paymentMethod);
    $savedDetails = $provider->getSavedPaymentDetails($processorContactId)->first();
    if ($savedDetails === NULL) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "no associated saved details - cannot update."
      );
    }
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $this->contributionRecurId)
      ->setValues(['invoice_id' => $processorContactId])
      ->execute();
    PaymentToken::update(FALSE)
      ->addWhere('id', '=', $recurInfo['payment_token_id'])
      ->setValues(['token' => $savedDetails->getToken()])
      ->execute();
    $result[] = [
      'contribution_recur_id' => $this->contributionRecurId,
      'invoice_id' => $processorContactId,
      'payment_token_id' => $recurInfo['payment_token_id'],
      'token' => $savedDetails->getToken()
    ];
  }
}

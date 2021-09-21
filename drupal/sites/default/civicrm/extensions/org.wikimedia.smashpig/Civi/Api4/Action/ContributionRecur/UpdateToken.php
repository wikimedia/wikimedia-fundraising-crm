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
 * @method $this setRecurInfo(array $recurInfo) Directly set recurring data.
 * @method string getRecurInfo() Get relevant recurring data.
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

  /**
   * For batch use, you can directly set the array we would otherwise look up
   * by the recurring contribution ID
   * @var array|NULL
   */
  protected $recurInfo = NULL;

  public function _run(Result $result) {
    if ($this->recurInfo === NULL) {
      $this->recurInfo = ContributionRecur::get(FALSE)
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
    }
    // Sanity checks
    if ($this->recurInfo['payment_processor_id.name'] !== $this->processorName) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "processor name {$this->recurInfo['payment_processor_id.name']} instead of " .
        "expected {$this->processorName}."
      );
    }
    if ($this->recurInfo['invoice_id'] !== NULL) {
      throw new \RuntimeException(
        "ContributionRecur with ID {$this->contributionRecurId} has " .
        "invoice_id {$this->recurInfo['invoice_id']} instead of expected null."
      );
    }
    $processorContactId = $this->recurInfo['payment_token_id.token'];
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
      $this->recurInfo
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
      ->addWhere('id', '=', $this->recurInfo['payment_token_id'])
      ->setValues(['token' => $savedDetails->getToken()])
      ->execute();
    $result[] = [
      'contribution_recur_id' => $this->contributionRecurId,
      'invoice_id' => $processorContactId,
      'payment_token_id' => $this->recurInfo['payment_token_id'],
      'token' => $savedDetails->getToken()
    ];
  }
}

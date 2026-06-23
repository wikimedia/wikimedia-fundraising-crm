<?php
namespace Civi\Api4\Action\PaymentAttempt;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentAttemptLabel;

/**
 * Labels a payment attempt as fraud or not fraud
 *
 * @method $this setIsFraud(bool $isFraud) Set fraud flag
 * @method bool getIsFraud() Get value of fraud flag.
 * @method $this setOrderID(string $orderID) Set order ID
 * @method string getOrderID() Get order ID.
 */
class Label extends AbstractAction {

  /**
   * @var bool
   * @required
   */
  protected $isFraud;

  /**
   * @var string
   * @required
   */
  protected $orderID;

  public function _run(Result $result) {
    $existingLabel = PaymentAttemptLabel::get(FALSE)
      ->addWhere('order_id', '=', $this->orderID)
      ->execute()->first();
    if ($existingLabel) {
      $labelID = $existingLabel['id'];
      PaymentAttemptLabel::update(FALSE)
        ->addWhere('id', '=', $labelID)
        ->addValue('is_fraud', $this->isFraud)
        ->execute();
    } else {
      $labelID = PaymentAttemptLabel::create(FALSE)
        ->addValue('order_id', $this->orderID)
        ->addValue('is_fraud', $this->isFraud)
        ->execute()['id'];
    }
    $result[] = [
      'label_id' => $labelID,
    ];
  }
}

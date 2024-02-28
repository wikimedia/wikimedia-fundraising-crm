<?php

namespace Civi\WMFQueueMessage;

use Civi\WMFException\FredgeDataValidationException;

class FredgeMessage {

  /**
   * WMF Fraud message.
   *
   * @var array
   */
  private array $message;

  private string $entity;

  /**
   * Constructor.
   *
   * @throws \Civi\WMFException\FredgeDataValidationException
   */
  public function __construct(array $message, string $entity, string $logIdentifier) {
    $this->message = $message;
    $this->entity = $entity;
    if (empty($this->message)) {
      $error = $logIdentifier . ": Trying to insert nothing for $entity. Dropping message on floor.";
      throw new FredgeDataValidationException($error);
    }
  }

  /**
   * Normalize the message.
   *
   * We filter out irrelevant fields (perhaps not needed)
   * and do a bit of wrangling on currency & risk score.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    return $this->getMessageValues($this->entity);
  }

  /**
   * Get the currency, falling back on currency_code if required.
   *
   * @return string
   */
  public function getCurrency(): string {
    return !empty($this->message['currency']) ? (string) $this->message['currency'] : (string) $this->message['currency_code'];
  }

  /**
   * Get the value to use for risk score.
   *
   * @param float $riskScore
   *
   * @return float
   */
  protected function getRiskScoreValue(float $riskScore): float {
    return min($riskScore, 100000000);
  }

  /**
   * @param $entity
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  protected function getMessageValues($entity): array {
    $fields = (array) civicrm_api4($entity, 'getfields', ['checkPermissions' => FALSE])->indexBy('name');
    $message = array_intersect_key($this->message, $fields);
    if (!empty($fields['currency_code'])) {
      $message['currency_code'] = $this->getCurrency();
    }
    if (!empty($fields['risk_score'])) {
      $message['risk_score'] = $this->getRiskScoreValue((float) $message['risk_score']);
    }
    if (!empty($message['date'])) {
      // Convert time stamp to date.
      $message['date'] = date('YmdHis', $message['date']);
    }
    return $message;
  }

}

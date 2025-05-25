<?php

class CRM_Damaged_DamagedRow {

  protected $id;

  protected $original_date;

  protected $damaged_date;

  protected $retry_date;

  protected $original_queue;

  protected $gateway;

  protected $order_id;

  protected $gateway_txn_id;

  protected $error;

  protected $trace;

  protected $message;

  protected $rawDamagedRow;

  public function __construct($damagedRow) {
    $this->setRawDamagedRow($damagedRow)
      ->setId($damagedRow['id']);

    if (array_key_exists('original_date', $damagedRow)) {
      $this->setOriginalDate($damagedRow['original_date']);
    }
    if (array_key_exists('damaged_date', $damagedRow)) {
      $this->setDamagedDate($damagedRow['damaged_date']);
    }
    if (array_key_exists('original_queue', $damagedRow)) {
      $this->setOriginalQueue($damagedRow['original_queue']);
    }
    if (array_key_exists('retry_date', $damagedRow)) {
      $this->setRetryDate($damagedRow['retry_date']);
    }
    if (array_key_exists('error', $damagedRow)) {
      $this->setError($damagedRow['error']);
    }
    if (array_key_exists('trace', $damagedRow)) {
      $this->setTrace($damagedRow['trace']);
    }
    if (array_key_exists('message', $damagedRow)) {
      $this->setMessage($damagedRow['message']);
    }
    if (array_key_exists('gateway_txn_id', $damagedRow)) {
      $this->setGatewayTxnId($damagedRow['gateway_txn_id']);
    }
    if (array_key_exists('order_id', $damagedRow)) {
      $this->setOrderId($damagedRow['order_id']);
    }
    if (array_key_exists('gateway', $damagedRow)) {
      $this->setGateway($damagedRow['gateway']);
    }
  }

  public function getId(): int {
    return $this->id;
  }

  public function setId($id): self {
    $this->id = $id;
    return $this;
  }

  public function getOriginalDate(): string {
    return $this->original_date;
  }

  public function setOriginalDate($orig_date): self {
    $this->original_date = $orig_date;
    return $this;
  }

  public function getDamagedDate(): string {
    return $this->damaged_date;
  }

  public function setDamagedDate($damaged_date): self {
    $this->damaged_date = $damaged_date;
    return $this;
  }

  public function getRetryDate(): string {
    return $this->retry_date;
  }

  public function setRetryDate($retry_date): self {
    $this->retry_date = $retry_date;
    return $this;
  }

  public function getOriginalQueue(): string {
    return $this->original_queue;
  }

  public function setOriginalQueue($queue): self {
    $this->original_queue = $queue;
    return $this;
  }

  public function getGateway(): string {
    return $this->gateway;
  }

  public function setGateway($gateway): self {
    $this->gateway = $gateway;
    return $this;
  }

  public function getOrderId(): string {
    return $this->order_id;
  }

  public function setOrderId($orderId): self {
    $this->order_id = $orderId;
    return $this;
  }

  public function getGatewayTxnId(): string {
    return $this->gateway_txn_id;
  }

  public function setGatewayTxnId($gatewayTxnId): self {
    $this->gateway_txn_id = $gatewayTxnId;
    return $this;
  }

  public function getError(): string {
    return $this->error;
  }

  public function setError($error): self {
    $this->error = $error;
    return $this;
  }

  public function getTrace(): string {
    return $this->trace;
  }

  public function setTrace($trace): self {
    $this->trace = $trace;
    return $this;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getMessage(): array {
    try {
      return json_decode($this->message, TRUE, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception ) {
      throw new \CRM_Core_Exception("Could not resend get row message: {$exception->getMessage()}");
    }
  }

  public function setMessage($message): self {
    if (is_string($message)) {
      $this->message = $message;
    }
    else {
      $this->message = json_encode($message);
    }
    return $this;
  }

  public function getRawDamagedRow(): array {
    $damagedRow = [
      'id' => $this->id,
      'original_date' => $this->original_date,
      'damaged_date' => $this->damaged_date,
      'retry_date' => $this->retry_date,
      'original_queue' => $this->original_queue,
      'gateway' => $this->gateway,
      'order_id' => $this->order_id,
      'gateway_txn_id' => $this->gateway_txn_id,
      'error' => $this->error,
      'trace' => $this->trace,
      'message' => $this->message,
    ];
    $this->setRawDamagedRow($damagedRow);
    return $this->rawDamagedRow;
  }

  public function setRawDamagedRow($rawDamagedRow): self {
    $this->rawDamagedRow = $rawDamagedRow;
    return $this;
  }

}

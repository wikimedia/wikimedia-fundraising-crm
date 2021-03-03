<?php

class CRM_Omnimail_IncompleteDownloadException extends CRM_Core_Exception {
  private $errorData = array();

  /**
   * Class constructor.
   *
   * @param string $message
   * @param int $error_code
   * @param array $errorData
   * @param null $previous
   */
  public function __construct($message, $error_code = 0, $errorData = array(), $previous = NULL) {
    parent::__construct($message);
    $this->errorData = $errorData;
  }

  public function getRetrievalParameters() {
    return $this->errorData['retrieval_parameters'];
  }

  public function getEndTimestamp() {
    return $this->errorData['end_date'];
  }
}
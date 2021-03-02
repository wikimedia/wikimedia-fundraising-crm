<?php

/**
 * Imports refunds from a CSV file
 *
 * @author Elliott Eggleston <eeggleston@wikimedia.org>
 */
class RefundFile {

  protected $processor;

  protected $file_uri;

  /**
   * @param string $processor name of the payment processor issuing refunds
   * @param string $file_uri path to the file
   */
  function __construct($processor, $file_uri) {
    $this->processor = $processor;
    $this->file_uri = $file_uri;
  }

  function import($offset = 1, $limit = 0) {
    if (!file_exists($this->file_uri)) {
      throw new WmfException(WmfException::FILE_NOT_FOUND, 'File not found: ' . $this->file_uri);
    }

    $file = fopen($this->file_uri, 'r');
    if ($file === FALSE) {
      throw new WmfException(WmfException::FILE_NOT_FOUND, 'Could not open file for reading: ' . $this->file_uri);
    }

    $headers = _load_headers(fgetcsv($file, 0, ','));
    $rowCount = 0;
    civicrm_initialize();
    while (($row = fgetcsv($file, 0, ',')) !== FALSE) {
      $rowCount += 1;
      $orderid = _get_value('Order ID', $row, $headers);
      $refundid = _get_value('Refund ID', $row, $headers, NULL);
      $currency = _get_value('Currency', $row, $headers);
      $amount = _get_value('Amount', $row, $headers);
      $date = _get_value('Date', $row, $headers);
      $refundType = _get_value('Type', $row, $headers, 'refund');
      $contributionId = _get_value('Contribution ID', $row, $headers);

      if ($orderid === '' && $contributionId === '') {
        watchdog(
          'offline2civicrm',
          "Need Order ID or Contribution ID for refund on row $rowCount",
          $row,
          WATCHDOG_INFO
        );
        continue;
      }

      if ($contributionId === '') {
        $logId = "{$this->processor} transaction $orderid";
        $contributions = wmf_civicrm_get_contributions_from_gateway_id($this->processor, $orderid);
      }
      else {
        $logId = "contribution with ID $contributionId";
        $contributions = wmf_civicrm_get_contributions_from_contribution_id($contributionId);
      }

      if (!$contributions) {
        watchdog(
          'offline2civicrm',
          "Could not find $logId",
          NULL,
          WATCHDOG_ERROR
        );
        continue;
      }

      $contribution = array_shift($contributions);
      $contributionId = $contribution['id'];

      // verify gateway in case we're retrieving by contribution ID
      if ($contribution['gateway'] !== $this->processor) {
        watchdog(
          'offline2civicrm',
          "$logId is from processor {$contribution['gateway']}, " .
          "not expected processor {$this->processor}",
          NULL,
          WATCHDOG_ERROR
        );
        continue;
      }

      // execute the refund
      wmf_civicrm_mark_refund(
        $contributionId,
        $refundType,
        TRUE,
        $date,
        $refundid,
        $currency,
        $amount
      );
      watchdog(
        'offline2civicrm',
        "Marked $logId refunded",
        NULL,
        WATCHDOG_INFO
      );
    }
  }
}

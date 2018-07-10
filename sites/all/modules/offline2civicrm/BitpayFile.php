<?php

class BitpayFile extends ChecksFile {
  protected function getRequiredColumns() {
    return array(
      'date',
      'time',
      'payout amount',
      'payout currency',
      'invoice price',
      'invoice currency',
    );
  }

  /**
   * Do any final transformation on a normalized and default-laden queue message.
   *
   * @param array $msg
   *
   * @throws \WmfException
   */
  protected function mungeMessage(&$msg) {
    list($msg['first_name'], $msg['last_name']) = wmf_civicrm_janky_split_name(CRM_Utils_Array::value('full_name', $msg));
    parent::mungeMessage($msg);
  }

  protected function getFieldMapping() {
    return [
      'payout currency' => 'currency',
      'invoice currency' => 'original_currency',
      'date' => 'date',
      'buyerEmail' => 'email',
      'buyerName' => 'full_name',
      'buyerState' => 'state_province',
      'buyerZip' => 'postal_code',
      'buyerAddress1' => 'street_address',
      'buyerAddress2' => 'supplemental_address_1',
      'buyerCity' => 'city',
      'buyerCountry' => 'country',
      'payout amount' => 'gross',
      'invoice price' => 'original_gross',
      'invoice id' => 'gateway_txn_id',
      'utm_source' => 'utm_source',
      'utm_campaign' => 'utm_campaign',
      'utm_medium' => 'utm_medium',
    ];
    // unmapped fields are
    // 'order id',,'tx type', 'exchange rate (EUR)','description', 'buyerPhone'
  }

  protected function getDefaultValues() {
    return array_merge(parent::getDefaultValues(), array(
        'gateway' => 'bitpay',
        'payment_instrument' => 'Bitcoin',
        'payment_method' => 'bitcoin',
        'contact_source' => 'Bitpay import',
      )
    );
  }

  /**
   * Read a row and transform into normalized queue message form
   *
   * @param $data
   *
   * @return array queue message format
   *
   * @throws \EmptyRowException
   * @throws \WmfException
   */
  protected function parseRow($data) {
    if ($data['tx type'] === 'Invoice Refund') {
      throw new WmfException(WmfException::INVALID_MESSAGE, 'Refunds not currently handled. Please log a Phab if required');
    }
    $validTypes = [
      'donation',
      'sale',
    ];
    if (!in_array($data['tx type'], $validTypes)) {
      throw new EmptyRowException();
    }

    return parent::parseRow($data);
  }

}

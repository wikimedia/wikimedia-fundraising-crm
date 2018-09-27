<?php

class StripeFile extends ChecksFile {
  protected function getRequiredColumns() {
    return array(
      'Created (UTC)',
      'Card Name',
      'Converted Amount',
      'id',
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
    list($msg['first_name'], $msg['last_name']) = wmf_civicrm_janky_split_name( $msg['full_name'] );
    $msg['currency'] = strtoupper($msg['currency']);
    $msg['original_currency'] = strtoupper($msg['original_currency']);
    parent::mungeMessage($msg);
  }

  protected function getFieldMapping() {
    return array_merge(parent::getFieldMapping(), [
      // Is this correct? - maps to gateway_refund_id' on refund?
      'id' => 'gateway_txn_id',
      'utm_source' => 'utm_source',
      'utm_campaign' => 'utm_campaign',
      'utm_medium' => 'utm_medium',
      'Card Address Line1' => 'street_address',
      'Card Address Line2' => 'supplemental_address_1',
      'Card Address City' => 'city',
      'Converted Amount' => 'gross',
      'Amount' => 'original_gross',
      'Created (UTC)' => 'date',
      'Customer Email' => 'email',
      'Converted Currency' => 'currency',
      'Currency' => 'original_currency',
      'Card Name' => 'full_name',
      'Card Address State' => 'state_province',
      'Card Address Zip' => 'postal_code',
      'Card Address Country' => 'country',

      //id,
      //Description,
      //Amount ,,
      //Amount Refunded,
      //Currency,
      //Converted Amount Refunded,Fee,
      //Tax,
      //Converted Currency,
      //Mode,
      //Status,
      //Statement Descriptor,
      //Customer ID,
      //Customer Description,,
      //Captured,Card ID,
      //Card Last4,
      //Card Brand,
      //Card Funding,
      //Card Exp Month,
      //Card Exp Year,
      //Card Issue Country,
      //Card Fingerprint,
      //Card CVC Status,
      //Card AVS Zip Status,
      //Card AVS Line1 Status,
      //Card Tokenization Method,
      //Disputed Amount,
      //Dispute Status,
      //Dispute Reason,
      //Dispute Date (UTC),
      //Dispute Evidence Due (UTC)
      //Invoice ID,
      //Payment Source Type,
      //Destination,
      //Transfer,
      //Transfer Group,
      //event_id (metadata),
      //event_name (metadata),
      //order_number (metadata)
    ]);
  }

  protected function getDefaultValues() {
    return array_merge(parent::getDefaultValues(), array(
        'gateway' => 'stripe',
        'no_thank_you' => 'stripe',
        'payment_instrument' => 'Stripe',
        'payment_method' => 'Stripe',
        'utm_medium' => 'MGEventEmail',
        'contact_source' => 'Stripe import',
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
    if (!empty($data['Converted Amount Refunded'])) {
      throw new WmfException(WmfException::INVALID_MESSAGE, 'Refunds not currently handled. Please log a Phab if required');
    }
    return parent::parseRow($data);
  }

}

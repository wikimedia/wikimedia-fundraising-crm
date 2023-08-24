<?php

/**
 * Class CitibankFile
 *
 * Imports Citibank csv.
 */
class CitibankIndividualsFile extends ChecksFile {

  protected function getRequiredColumns() {
    return [
      'Posted Dt.',
      'Doc',
      'Curr',
      'Txn Amt',
      'Debit',
    ];
  }

  protected function getFieldMapping() {
    return [
      'Doc' => 'gateway_txn_id',
      'Debit' => 'gross',
      'Txn Amt' => 'original_gross',
      'Posted Dt.' => 'settlement_date',
      'Doc Dt.' => 'date',
      'Curr' => 'original_currency',
    ];
  }

  protected function getDatetimeFields() {
    return [
      'date',
      'settlement_date',
    ];
  }

  protected function mungeMessage(&$msg) {
    parent::mungeMessage($msg);
    $msg['gateway'] = 'citibank';
    $msg['gift_source'] = $this->getGiftSource($msg);
    $msg['contact_id'] = $this->getCitiBankContactID();
  }

  protected function getDefaultValues() {
    return array_merge(parent::getDefaultValues(), [
      'contact_source' => 'citibank import',
      'gateway' => 'citibank',
      'no_thank_you' => 'No Contact Details',
      'payment_instrument' => 'Citibank International',
      'currency' => 'USD',
    ]);
  }

  /**
   * Get the generic contact id we will use for Citibank.
   *
   * These are anonymous but using a separate contact seems helpful?
   *
   * @return int
   */
  protected function getCitiBankContactID() {
    static $contactID = NULL;
    if (!$contactID) {
      $contactID = (int) civicrm_api3('Contact', 'getvalue', [
        'return' => 'id',
        'contact_type' => 'Individual',
        'last_name' => 'Citibank',
        'email' => 'fakecitibankemail@wikimedia.org',
      ]);
    }
    return $contactID;
  }

  /**
   * Get the appropriate gift source.
   *
   * If the USD value is $1000 of more it will be 'Benefactor Gift', otherwise 'Community Gift'
   *
   * @param array $msg
   *
   * @return string
   */
  protected function getGiftSource($msg): string {
    // return $msg['gross'] >= 1000 ? 'Benefactor Gift' : 'Community Gift';
    return 'Individual Gift';
  }

}

<?php

/**
 * Class CitibankFile
 *
 * Imports Citibank csv.
 */
class CitibankFile extends ChecksFile {

  protected function getRequiredColumns() {
    return array(
      'Date Received',
      'Date Posted',
      'Amount',
      'Originator Name',
      'Global Reference Number',
    );
  }

  protected function getRequiredData() {
    return parent::getRequiredData() + array(
        'gateway_txn_id',
      );
  }

  protected function getFieldMapping() {
    return array(
      'Account Name' => 'gateway_account',
      'Global Reference Number' => 'gateway_txn_id',
      'Amount' => 'gross',
      'Date Posted' => 'settlement_date',
      'Date Received' => 'date',
      'Originator Name' => 'organization_name',
      // Should we map this anywhere - not super informative but?
      //'Special Instructions Line 1' => 'source',
    );
  }

  protected function getDatetimeFields() {
    return array(
      'date',
      'settlement_date',
    );
  }

  protected function mungeMessage(&$msg) {
    parent::mungeMessage($msg);
    if (substr($msg['organization_name'], 0, 2) == '1/') {
      // For reasons that are unclear sometimes this string appears at the start of the Originator Name field.
      $msg['organization_name'] = substr($msg['organization_name'], 2);
    }

  }

  protected function getDefaultValues() {
    return array_merge(parent::getDefaultValues(), array(
      'contact_type' => 'Organization',
      'contact_source' => 'citibank import',
      'gateway' => 'citibank',
      'gift_source' => 'Community Gift',
      'no_thank_you' => 'No Contact Details',
      'payment_instrument' => 'Citibank International',
      'restrictions' => 'Unrestricted - General',
      'currency' => 'USD',
    ));
  }

}

<?php

use Civi\Api4\Contact;
use Civi\WMFException\WMFException;

class FidelityFile extends ChecksFile {

  /**
   * Contact to soft credit to.
   *
   * @var int
   */
  protected $softCreditToID;

  /**
   * Get soft credit to ID.
   *
   * @return int
   *
   * @throws \API_Exception
   */
  public function getSoftCreditToID(): int {
    if (!$this->softCreditToID) {
      $this->softCreditToID = Contact::get(FALSE)
        ->addWhere('organization_name', '=', 'Fidelity Charitable Gift Fund')
        ->execute()->first()['id'];
    }
    if (!$this->softCreditToID) {
      $this->softCreditToID = Contact::create(FALSE)
        ->setValues([
          'organization_name' => 'Fidelity Charitable Gift Fund',
          'contact_type' => 'Organization',
        ])
        ->execute()->first()['id'];
    }
    return $this->softCreditToID;
  }

  /**
   * Get the required columns.
   *
   * @return string[]
   */
  protected function getRequiredColumns(): array {
    return [
      'Effective Date',
      'Grant Amount',
    ];
  }

  /**
   * Get the field mapping.
   *
   * @return string[]
   */
  protected function getFieldMapping(): array {
    return [
      'Effective Date' => 'date',
      'Grant Amount' => 'gross',
      'Acknowledgement Address Line 1' => 'street_address',
      'Acknowledgement Address Line 2' => 'supplemental_address_1',
      'Acknowledgement Address Line 3' => 'supplemental_address_2',
      'Acknowledgement City' => 'city',
      'Acknowledgement State' => 'state_province',
      'Acknowledgement ZipCode' => 'postal_code',
      'Acknowledgement Country' => 'country',
      'Giving Account Name' => 'organization_name',
      'Addressee Name' => 'full_name',
      'Grant Id' => 'gateway_txn_id',
    ];
  }

  /**
   * Get the default values.
   *
   * @return string[]
   * @throws \API_Exception
   */
  protected function getDefaultValues(): array {
    return [
      'soft_credit_to_id' => $this->getSoftCreditToID(),
      'contact_type' => 'Individual',
      'country' => 'US',
      'currency' => 'USD',
      'payment_instrument_id' => 'EFT',
      'gift_source' => 'Donor Advised Fund',
      'restrictions' => 'Restricted - Foundation',
    ];
  }

  /**
   * Do any final transformation on a normalized and default-laden queue
   * message.
   *
   * @param array $msg
   *   The normalized import parameters.
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function mungeMessage(&$msg): void {
    $msg['gateway'] = 'fidelity';
    parent::mungeMessage($msg);
    if (!empty($msg['full_name'])) {
      $msg['contact_type'] = 'Individual';
    }
    elseif ($msg['organization_name'] === 'Anonymous') {
      $msg['contact_id'] = $this->getAnonymousContactID();
    }
    else {
      $msg['contact_type'] = 'Organization';
      try {
        $msg['id'] = $this->getOrganizationID($msg['organization_name']);
      }
      catch (WMFException $e) {
        $msg['contact_type'] = 'Organization';
      }
    }
  }

}

<?php

class EngageChecksFile extends ChecksFile {
    function getRequiredColumns() {
        return array(
            'Batch',
            'Check Number',
            'City',
            'Contribution Type',
            'Country',
            'Direct Mail Appeal',
            'Email',
            'Gift Source',
            'Payment Instrument',
            'Postal Code',
            'Postmark Date',
            'Received Date',
            'Restrictions',
            'Source',
            'State',
            'Street Address',
            'Thank You Letter Date',
            'Total Amount',
        );
    }

    function getRequiredData() {
        return parent::getRequiredData() + array(
            'check_number',
            'gift_source',
            'import_batch_number',
            'payment_method',
            'restrictions',
        );
    }

  /**
   * Do any final transformation on a normalized queue message.
   *
   * @param array $msg
   *
   * @throws \WmfException
   */
  protected function mungeMessage(&$msg) {
    parent::mungeMessage($msg);
    $msg['gateway'] = "engage";
    $msg['contribution_type'] = "engage";
    $msg['contact_id'] = $this->getContactID($msg);
    if ($msg['contact_type'] === 'Individual' && $msg['contact_id'] == $this->getAnonymousContactID()) {
      $this->unsetAddressFields($msg);
    }
  }

  /**
   * Get the ID of a matching individual.
   *
   * Refer to https://phabricator.wikimedia.org/T115044#3012232 for discussion of logic.
   *
   * @param array $msg
   *
   * @return int|NULL
   *   Contact ID to use, if no integer is returned a new contact will be created
   *
   * @throws \WmfException
   */
  protected function getContactID($msg) {
    if ($msg['contact_type'] === 'Individual' &&
      (empty($msg['email']) && empty($msg['street_address']) && empty($msg['postal_code']))
      && ($msg['first_name'] === 'Anonymous' && $msg['last_name'] === 'Anonymous')
    ) {
      try {
        // We do not have an email, usable address or a name, match to our anonymous contact (
        // remaining address details such as city, state are discarded in this case)
        return $this->getAnonymousContactID();
      }
      catch (CiviCRM_API3_Exception $e) {
        throw new WmfException(
          WmfException::IMPORT_CONTRIB,
          t("The donation is anonymous but the anonymous contact is ambiguous. Ensure exactly one contact is in CiviCRM with the email fakeemail@wikimedia.org' and first name and last name being Anonymous "
          )
        );
      }
    }

    $params = [
      'sequential' => TRUE,
      'contact_id.contact_type' => $msg['contact_type'],
      'contact_id.is_deleted' => 0,
      // we need to return the custom field (for now) as a core bug is not adding the table on sort only.
      'return' => ['contact_id.id', 'contact_id.' . wmf_civicrm_get_custom_field_name('last_donation_date')],
      'options' => ['sort' => 'contact_id.' . wmf_civicrm_get_custom_field_name('last_donation_date') . ' DESC'],
    ];
    if ($msg['contact_type'] === 'Individual') {
      if (empty($msg['first_name']) || empty($msg['last_name'])) {
        return NULL;
      }
      $params['contact_id.first_name'] = $msg['first_name'];
      $params['contact_id.last_name'] = $msg['last_name'];
    }
    else {
      if (empty($msg['organization_name'])) {
        return NULL;
      }
      $params['contact_id.organization_name'] = $msg['organization_name'];
    }

    if (!empty($msg['email'])) {
      $params['email'] = $msg['email'];
      $contacts = civicrm_api3('Email', 'get', $params);
      if ($contacts['count']) {
        return $contacts['values'][0]['contact_id.id'];
      }
    }

    // Try for address match.

    foreach (['street_address', 'postal_code', 'city'] as $addressCheckField) {
      if (empty($msg[$addressCheckField])) {
        return NULL;
      }
      if ($addressCheckField === 'street_address') {
        // Engage are inconsistent regarding a trailing '.' so we test with & without.
        $addressesToMatch = [$msg[$addressCheckField]];
        if (substr($msg[$addressCheckField], -1, 1) === '.') {
          $addressesToMatch[] = substr($msg[$addressCheckField], 0, -1);
        }
        else {
          $addressesToMatch[] = $msg[$addressCheckField] . '.';
        }
        $params[$addressCheckField] = ['IN' => $addressesToMatch];
      }
      else {
        $params[$addressCheckField] = $msg[$addressCheckField];
      }
    }
    $contacts = civicrm_api3('Address', 'get', $params);
    if ($contacts['count']) {
      return $contacts['values'][0]['contact_id.id'];
    }
  }

}

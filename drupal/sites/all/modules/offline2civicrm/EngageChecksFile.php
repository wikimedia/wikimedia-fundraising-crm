<?php

use Civi\WMFException\WMFException;

class EngageChecksFile extends ChecksFile {

  /**
   * The import type descriptor.
   *
   * @var string
   */
  protected $gateway = 'engage';

  function getRequiredColumns() {
    return [
      'Batch',
      'Check Number',
      'City',
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
    ];
  }

  function getRequiredData() {
    return parent::getRequiredData() + [
        'check_number',
        'gift_source',
        'import_batch_number',
        'payment_method',
        'restrictions',
      ];
  }

  /**
   * Get the defaults to use if not in the csv.
   *
   * @return array|string[]
   */
  protected function getDefaultValues(): array {
    return array_merge(parent::getDefaultValues(), ['financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Engage')]);
  }

  /**
   * Do any final transformation on a normalized queue message.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  protected function mungeMessage(&$msg) {
    parent::mungeMessage($msg);
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
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
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
      catch (CRM_Core_Exception $e) {
        throw new WMFException(
          WMFException::IMPORT_CONTRIB,
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
      'return' => ['contact_id.id', 'contact_id.' . $this->wmf_civicrm_get_custom_field_name('last_donation_date')],
      'options' => ['sort' => 'contact_id.' . $this->wmf_civicrm_get_custom_field_name('last_donation_date') . ' DESC'],
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


  /**
   * @param $field_name
   * @param null $group_name
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   * @deprecated - try ot use apiv4 instead.
   *
   */
  private function wmf_civicrm_get_custom_field_name($field_name, $group_name = NULL) {
    return 'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID($field_name, $group_name);
  }

}

<?php

class CRM_Deduper_BAO_PreferredContact  {

  /**
   * Contact 1.
   *
   * @var int
   */
  protected $contact1;

  /**
   * Contact 2.
   *
   * @var int
   */
  protected $contact2;

  /**
   * CRM_Deduper_BAO_PreferredContact constructor.
   *
   * @param int $contact1
   * @param int $contact2
   */
  public function __construct($contact1, $contact2) {
    $this->contact1 = $contact1;
    $this->contact2 = $contact2;
  }

  /**
   * Get the ID of the preferred contact.
   *
   * @return int
   *
   * @throws \CRM_Core_Exception
   */
  public function getPreferredContactID(): int {
    $methods = (array) Civi::settings()->get('deduper_resolver_preferred_contact_resolution');
    $methods[] = Civi::settings()->get('deduper_resolver_preferred_contact_last_resort');
    foreach ($methods as $method) {
      $contactID = $this->resolvePreferredContactByMethod($method);
      if  ($contactID) {
        return (int) $contactID;
      }
    }
    throw new CRM_Core_Exception('All contacts should resolve due to resolver of last resort kicking in');
  }

  /**
   * Resolve which contact is preferred according to the method.
   *
   * @param string $method
   *
   * @return bool|int
   *   Preferred contact ID or FALSE if neither is preferred based on the method.
   *
   * @throws \CRM_Core_Exception
   */
  protected function resolvePreferredContactByMethod($method) {
    switch ($method) {
      case 'most_recently_created_contact':
        return $this->contact1 > $this->contact2 ? $this->contact1 : $this->contact2;

      case 'earliest_created_contact':
        return $this->contact1 < $this->contact2 ? $this->contact1 : $this->contact2;

      case 'most_recently_modified_contact':
        // You might think the merge handler already got passed this info and we could save a DB look up
        // but, you would be wrong - core didn't pass it through the hooks.
        $contacts = civicrm_api3('Contact', 'get', ['id' => ['IN' => [$this->contact1, $this->contact2]], 'return' => 'modified_id'])['values'];
        if ($contacts[$this->contact1]['modified_date'] > $contacts[$this->contact2]['modified_date']) {
          return $this->contact1;
        }
        return $this->contact2;

      case 'earliest_modified_contact':
        $contacts = civicrm_api3('Contact', 'get', ['id' => ['IN' => [$this->contact1, $this->contact2]], 'return' => 'modified_id'])['values'];
        if ($contacts[$this->contact1]['modified_date'] < $contacts[$this->contact2]['modified_date']) {
          return $this->contact1;
        }
        return $this->contact2;

      case 'most_recent_contributor' :
        $lastDonor = civicrm_api3('Contribution', 'get', [
          'return' => 'contact_id',
          'contact_id' => ['IN' => [$this->contact1, $this->contact2]],
          'sequential' => 1,
          'options' => ['sort' => 'receive_date DESC', 'limit' => 1],
        ])['values'];
        if (empty($lastDonor[0])) {
          return FALSE;
        }
        return $lastDonor[0]['contact_id'];

      case 'most_prolific_contributor' :
        $contact1Count = civicrm_api3('Contribution', 'getcount', ['contact_id' => $this->contact1, 'contribution_status_id' => 'Completed']);
        $contact2Count = civicrm_api3('Contribution', 'getcount', ['contact_id' => $this->contact2, 'contribution_status_id' => 'Completed']);
        if ($contact1Count > $contact2Count) {
          return $this->contact1;
        }
        if ($contact2Count > $contact1Count) {
          return $this->contact2;
        }
        return FALSE;
    }
    return FALSE;
  }
}

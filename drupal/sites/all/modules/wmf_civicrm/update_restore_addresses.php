<?php
/**
 * Fix data loss through blanked address
 *
 * @param int $batch
 *   How many to process.
 * @param int $start
 *   Number to start at.
 *
 * @return int
 *   The number to start at for the next batch.
 */
function repair_lost_addresses_batch($batch = 5000, $start = 0) {
  civicrm_initialize();
  $result = CRM_Core_DAO::executeQuery("
  SELECT id FROM civicrm_address
  WHERE street_address IS NULL
   AND city IS NULL
   AND postal_code IS NULL
   AND state_province_id IS NULL
   AND country_id IS NULL
   AND id > $start
   LIMIT $batch");
  while ($result->fetch()) {
    wmf_civicrm_fix_blanked_address($result->id);
  }
  return $result->id;
}

/**
 * Fix data loss through blanked address for an individual address.
 *
 * We have a situation where a number of addresses have been overwritten by blank addresses.
 *
 * Unfortunately some of these have since been merged, resulting in some unravelling to be done.
 *
 * This update seeks to unravel ones with a single merge since then.
 *
 * @param int $addressID
 */
function wmf_civicrm_fix_blanked_address($addressID) {
  $result = CRM_Core_DAO::executeQuery('SELECT * FROM log_civicrm_address WHERE id = %1 ORDER BY log_date', array(
    1 => array(
      $addressID,
      'Int'
    )
  ));
  while ($result->fetch()) {
    $logEntries[] = (array) $result;
  }
  $highestLogID = count($logEntries) - 1;

  $dataFields = array(
    'street_address',
    'city',
    'postal_code',
    'state_province_id',
    'country_id',
    'supplemental_address_1',
    'supplemental_address_2',
    'supplemental_address_3',
    'county_id',
    'postal_code_suffix',
    'name'
  );

  // We want to make sure both the creation of the address and the update record
  // don't hold any address data. Otherwise it is a more complex update.
  foreach ($dataFields as $dataField) {
    if (!empty($logEntries[0][$dataField]) || !empty($logEntries[1][$dataField])) {
      // Not blank enough to process.
      return;
    }
  }

  $deletedAddresses = array();
  foreach ($logEntries as $logEntryID => $logEntry) {
    if ($logEntryID === 0 || in_array($logEntries[$logEntryID]['log_conn_id'], array(
        // Bulk update on 2017-02-07 involving 11712 records.
        // Consistent with the fix to primary addresses.
        // I committed the relevant fix on
        // commit 7b287e68292da1ae161aa2383808d8925af4a583
        // Author: eileen <emcnaughton@wikimedia.org>
        // Date:   Thu Feb 2 15:41:58 2017 +1300
        '589a35f1e77ec',
        // Buld update on 2016-11-02 involving 4458473 records
        // Consistent with first attempt to add (non-rounded) geocodes.
        //commit e4bf03d321f975a21510337f75e088c235c49521
        // Author: Elliott Eggleston <ejegg@ejegg.com>
        // Date:   Tue Nov 1 14:18:31 2016 -0500
        // Geocode existing US addresses
        '581939a5f3bc8',
        // Bulk update on 2016-11-09 21:28:43 involving 4715708 records
        // consistent with fixing the rounding on prior changes.
        //commit 25558f9b27b2b2d0be17d1dd25e16ff4d0708a96
        // Author: eileen <emcnaughton@wikimedia.org>
        // Date:   Thu Nov 10 10:01:36 2016 +1300
        // Fix rounding of lat/long caused by DOUBLE wierdness.
        // (note I think the commit is in my tz).
        '5823950b11501',
        // another bulk update, not fully diagnosed but around 7000 records.
        '581923f4ee6ec',
      ))) {
      continue;
    }
    // Fetch all the address changes that happened during the most recent merge action.
    $logs = civicrm_api3('Logging', 'get', array(
      'tables' => array('civicrm_address'),
      'log_conn_id' => $logEntries[$logEntryID]['log_conn_id']
    ));
    foreach ($logs['values'] as $log) {
      if ($log['action'] == 'Delete') {
        $deletedAddresses[$log['id']][$log['field']] = $log['from'];
      }
    }
  }
  $updates = array();

  /**
   * Some checks / precautions.
   * - how many times has this address been changed?
   * - only 2? we are just dealing with a single address moved from one
   *   contact to another (double check that because we like belts & braces, red ones).
   * - more than 2? ug complications. - give it a go working with the most recent one.
  */
  if (
    !in_array($logEntries[0]['log_action'], array('Insert', 'Initialization'))
    || $logEntries[$highestLogID]['log_action'] !== 'Update'
    ) {
    return;
  }

  // We are specifically trying to handle merged data at this stage.
  // May extend if we identify other patterns.
  if ($logEntries[0]['contact_id'] == $logEntries[$highestLogID]['contact_id']) {
    return;
  }

  // We definitely have a situation where a blank record was inserted & then, on merge
  // was transferred to another contact. We have the log_conn_id & the new contact id.
  // let's make sure the new contact has not since been deleted (merged)
  $keptContact = civicrm_api3('Contact', 'get', array(
    'id' => $logEntries[$highestLogID]['contact_id'],
    'sequential' => 1
  ));
  if ($keptContact['count'] == 0 || !empty($keptContact['values'][0]['is_deleted'])) {
    // subject to a subsequent merge & deleted, next round for these.
    return;
  }

  // If our address is NOT the primary
  $addresses = civicrm_api3('Address', 'get', array(
    'contact_id' => $logEntries[$highestLogID]['contact_id'],
  ));
  $emptyAddress = $addresses['values'][$addressID];
  $isPrimary = $emptyAddress['is_primary'];

  // Let's first establish if there was only one address deleted in the merge.
  // if so, we're gonna get through this. If not, bottle out.
  // Let's say... if OUR address is the primary and one address was deleted then
  // we are dealing with a change to our address.
  // If our address is not primary, but the deleted address is, it probably is
  // not related to ours & we should just delete ours & not recover it.
  // we are getting into diminishing returns with non-primaries....
  if (count($deletedAddresses) === 1) {
    $deletedAddress = reset($deletedAddresses);
    foreach ($deletedAddress as $fieldName => $value) {
      if (in_array($fieldName, $dataFields) && ($isPrimary || $deletedAddress['is_primary'] === 0)) {
        $updates[$fieldName] = $value;
      }
    }
  }
  else {
    // Here is what we know about the record
    // 1) it was inserted blank & it remains blank
    // 2) it was transferred from one contact to another during the
    // merge.
    // But... was another address deleted to make way for it?
    // If more than one address was deleted we will select the last
    // one which is close enough to latest for this last round.
    if (count($deletedAddresses) > 1)  {
      foreach ($deletedAddresses as $deletedAddress) {
        $deletedAddressDetails = array_intersect_key($deletedAddress, array_fill_keys($dataFields, 1));
        foreach ($addresses['values'] as $address) {
          $addressDetails = array_intersect_key($address, $deletedAddressDetails);
          if ($deletedAddressDetails === $addressDetails) {
            // This is our last check. Basically $addressDetails holds a subset of the
            // address keys that matches those present in the deleted address Details. If it exactly matches
            // the deleted address then let's ignore the deleted address & move on.
            continue;
          }
          $updates = $deletedAddressDetails;
        }
      }
    }
  }

  if (empty($updates)) {
    // We are dealing with an address that was created blank & then merged
    // to a contact without any loss of address data.
    // Just get rid of the blank address.
    civicrm_api3('Address', 'Delete', array('id' => $addressID));
  }
  else {
    $updates['id'] = $addressID;
    civicrm_api3('Address', 'create', $updates);
  }

  // Remove from the tracking table.
  CRM_Core_DAO::executeQuery('DELETE FROM blank_addresses WHERE id = %1', array(
    1 => array(
      $addressID,
      'Int'
    )
  ));

}

<?php

use Civi\WMFException\WMFException;

/**
 * Loads the column headers into an array so that they can be used independent
 * of the column order when generating messages
 *
 * @param $row Array containing the column headers for the csv
 *
 * @return Array mapping the header keys to the column index
 * @throws Civi\WMFException\WMFException if a duplicate header is found
 */
function _load_headers($row) {
  $header_keys = [];

  $used = []; // uppercased headers
  $duplicates = [];
  # check for duplicates, case insensitive
  foreach ($row as $i => $k) {
    $upper = strtoupper($k);
    // Ignore empty labels.
    if (!$upper) {
      continue;
    }
    if (array_key_exists($upper, $used)) {
      $duplicates[] = $k;
    }
    $header_keys[trim($k)] = $i;
    $used[$upper] = TRUE;
  }
  if ($duplicates) {
    throw new WMFException(WMFException::INVALID_FILE_FORMAT, 'Duplicate column headers: ' . implode(", ", $duplicates));
  }
  \Civi::log('wmf')->info('offline2civicrm: Import checks: Column headers loaded from file');

  return $header_keys;
}

function _get_value($column, $row, $headers, $default = '') {

  if (!array_key_exists($column, $headers)) {
    return $default;
  }
  else {
    $value = trim($row[$headers[$column]]);
    if (empty($value)) {
      return $default;
    }
    return $value;
  }
}

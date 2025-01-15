<?php

define('WMF_DATEFORMAT', 'Ymd');

/**
 * Convert a unix timestamp to formatted date, in UTC.
 *
 * Ordinarily, you will want to use the pre-formatted functions below to ensure
 * standardized behavior.
 *
 * @param string $format format appropriate for the php date() function
 * @param integer $unixtime timestamp, seconds since epoch
 *
 * @return string Formatted time
 */
function wmf_common_date_format_using_utc($format, $unixtime) {
  try {
    $obj = wmf_common_make_datetime('@' . $unixtime);
    $formatted = $obj->format($format);
  }
  catch (Exception $ex) {
    \Civi::log('wmf')->error('wmf_common: Caught date exception in ' . __METHOD__ . ': ' . $ex->getMessage());
    return '';
  }

  return $formatted;
}

/**
 * Normalize a date string and attempt to parse into a DateTime object.
 *
 * @return DateTime
 * @throws Exception when the string is unparsable.
 */
function wmf_common_make_datetime($text) {
  // Funky hack to trim decimal timestamp.  More normalizations may follow.
  $text = preg_replace('/^(@\d+)\.\d+$/', '$1', $text);

  return new DateTime($text, new DateTimeZone('UTC'));
}

/**
 * Used to format dates for the CiviCRM API.
 *
 * @param string $unixtime unix timestamp in seconds since epoch
 *
 * @return string Formatted time
 */
function wmf_common_date_unix_to_civicrm($unixtime) {
  return wmf_common_date_format_using_utc("Y-m-d H:i:s", $unixtime);
}

/**
 * Used to format dates for MySQL datetime columns.
 *
 * @param string $unixtime unix timestamp in seconds since epoch
 *
 * @return string Formatted time
 */
function wmf_common_date_unix_to_sql($unixtime) {
  return wmf_common_date_format_using_utc("YmdHis", $unixtime);
}

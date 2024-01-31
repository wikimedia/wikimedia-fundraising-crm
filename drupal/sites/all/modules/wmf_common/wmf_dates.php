<?php

define('WMF_DATEFORMAT', 'Ymd');

/**
 * Converts various kinds of dates to our favorite string format.
 *
 * @param mixed $date An integer in ***timestamp*** format, or a DateTime
 *   object.
 *
 * @return string The date in the format yyyymmdd.
 */
function wmf_common_date_format_string($date) {
  if (is_numeric($date)) {
    return date(WMF_DATEFORMAT, $date);
  }
  elseif (is_object($date)) {
    return date_format($date, WMF_DATEFORMAT);
  }
}

/**
 * Run strtotime in UTC
 *
 * @param string $date Random date format you hope is parseable by PHP, and is
 * in UTC.
 *
 * @return int Seconds since Unix epoch
 */
function wmf_common_date_parse_string($date) {
  try {
    $obj = wmf_common_make_datetime($date);
    return $obj->getTimestamp();
  }
  catch (Exception $ex) {
    \Civi::log('wmf')->error('wmf_common: Caught date exception in ' . __METHOD__ . ': ' . $ex->getMessage());
    return NULL;
  }
}

/**
 * Adds a number of days to a specified date
 *
 * @param string $date Date in a format that date_create recognizes.
 * @param int $add Number of days to add
 *
 * @return integer Date in WMF_DATEFORMAT
 */
function wmf_common_date_add_days($date, $add) {
  $date = date_create($date);
  date_add($date, date_interval_create_from_date_string("$add days"));

  return date_format($date, WMF_DATEFORMAT);
}

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
 * @throws Exception when the string is unparsable.
 * @return DateTime
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

/**
 * Convert civi api Y-m-d H:i:s to unix seconds
 *
 * @param string $date as Civi timestamp, returned by an api call
 *
 * @return int unix epoch seconds
 */
function wmf_common_date_civicrm_to_unix($date) {
  return DateTime::createFromFormat('Y-m-d H:i:s', $date, new DateTimeZone('UTC'))
    ->getTimestamp();
}

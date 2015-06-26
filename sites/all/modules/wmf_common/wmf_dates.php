<?php

define('WMF_DATEFORMAT', 'Ymd');
define('WMF_MIN_ROLLUP_YEAR', 2006);
define('WMF_MAX_ROLLUP_YEAR', 2025);

/**
 * Converts various kinds of dates to our favorite string format. 
 * @param mixed $date An integer in ***timestamp*** format, or a DateTime object.
 * @return string The date in the format yyyymmdd.
 */
function wmf_common_date_format_string( $date ){
	if ( is_numeric( $date ) ){
		return date(WMF_DATEFORMAT, $date);
	} elseif( is_object( $date ) ) {
		return date_format($date, WMF_DATEFORMAT);
	}
}

/**
 * Run strtotime in UTC
 * @param string $date Random date format you hope is parseable by PHP, and is
 * in UTC.
 * @return int Seconds since Unix epoch
 */
function wmf_common_date_parse_string( $date ){
    try {
        $obj = new DateTime( $date, new DateTimeZone( 'UTC' ) );
        return $obj->getTimestamp();
    } catch ( Exception $ex ) {
        watchdog( 'wmf_common', t( "Caught date exception: " ) . $ex->getMessage(), NULL, WATCHDOG_ERROR );
        return null;
    }
}

/**
 * Returns today's date string value
 * @return int Today's date in the format yyyymmdd. 
 */
function wmf_common_date_get_today_string(){
	$timestamp = time();
	return wmf_common_date_format_string( $timestamp );
}

/**
 * Get an array of all the valid dates between $start(exclusive) and $end(inclusive)
 * @param int $start Date string in the format yyyymmdd
 * @param int $end Date string in the format yyyymmdd
 * @return An array of all date strings between the $start and $end values
 */
function wmf_common_date_get_date_gap( $start, $end ){
	$startdate = date_create_from_format(WMF_DATEFORMAT, (string)$start);
	$enddate = date_create_from_format(WMF_DATEFORMAT, (string)$end);
	
	$next = $startdate;
	$interval = new DateInterval('P1D');
	$ret = array();
	while ( $next < $enddate ){
		$next = date_add( $next, $interval );
		$ret[] = wmf_common_date_format_string( $next );
	}
	return $ret;
}

/**
 * Adds a number of days to a specified date
 * @param string $date Date in a format that date_create recognizes.
 * @param int $add Number of days to add
 * @return integer Date in WMF_DATEFORMAT
 */
function wmf_common_date_add_days( $date, $add ){
	$date = date_create( $date );
	date_add($date, date_interval_create_from_date_string("$add days"));
	
	return date_format($date, WMF_DATEFORMAT);
}

/**
 * Convert a unix timestamp to formatted date, in UTC.
 *
 * Ordinarily, you will want to use the pre-formatted functions below to ensure standardized behavior.
 * 
 * @param string $format format appropriate for the php date() function
 * @param integer $unixtime timestamp, seconds since epoch
 * @return string Formatted time
 */
function wmf_common_date_format_using_utc( $format, $unixtime ) {
    try {
        $obj = new DateTime( '@' . $unixtime, new DateTimeZone( 'UTC' ) );
        $formatted = $obj->format( $format );
    } catch ( Exception $ex ) {
        watchdog( 'wmf_common', t( "Caught date exception: " ) . $ex->getMessage(), NULL, WATCHDOG_ERROR );
        return '';
    }

    return $formatted;
}

/**
 * Used to format dates for the CiviCRM API.
 *
 * @param string $unixtime unix timestamp in seconds since epoch
 */
function wmf_common_date_unix_to_civicrm( $unixtime ) {
    return wmf_common_date_format_using_utc( "Y-m-d H:i:s", $unixtime );
}

/**
 * Used to format dates for MySQL datetime columns.
 *
 * @param string $unixtime unix timestamp in seconds since epoch
 */
function wmf_common_date_unix_to_sql( $unixtime ) {
    return wmf_common_date_format_using_utc( "YmdHis", $unixtime );
}

/**
 * Convert civi api Y-m-d H:i:s to unix seconds
 * @param string $date as Civi timestamp, returned by an api call
 * @return int unix epoch seconds
 */
function wmf_common_date_civicrm_to_unix( $date ) {
    return DateTime::createFromFormat( 'Y-m-d H:i:s', $date, new DateTimeZone( 'UTC' ) )
        ->getTimestamp();
}

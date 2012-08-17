<?php

define('WMF_DATEFORMAT', 'Ymd');

/**
 * Converts various kinds of dates to our favorite string format. 
 * @param mixed $date An integer in ***timestamp*** format, or a DateTime object.
 * @return int The date in the format yyyymmdd. 
 */
function wmf_common_date_format_string( $date ){
	if ( is_numeric( $date ) ){
		return date(WMF_DATEFORMAT, $date);
	} elseif( is_object( $date ) ) {
		return date_format($date, WMF_DATEFORMAT);
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
	$startdate = date_create_from_format(WMF_DATEFORMAT, $start);
	$enddate = date_create_from_format(WMF_DATEFORMAT, $end);
	
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


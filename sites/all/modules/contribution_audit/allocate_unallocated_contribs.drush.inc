<?php
/**
 * @file allocate_unallocated_contribs.drush.inc
 *  Find missing and otherwise unallocated transactions and aggregate 
 *  contribs by country
 * @author Arthur Richards <arichards@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

/**
 * Implementation of hook_drush_command()
 */
function allocate_unallocated_contribs_drush_command() {
  $items = array();

  $items['allocate-unallocated-contribs'] = array(
    'description' => 
      'Finds missing and otherwise unallocated transactions and aggregates the contrib amounts by country.',
    'options' => array(
      'start' => 'The start date from which you wish to find unallocated contribs.  Can be anything interpretable by strtotime().',
      'end' => 'The end date for which you wish to find unallocated contribs.  Can be anything interpretable by strtotime().'
    ),  
    'examples' => array( 'drush allocate-unallocated-contribs  --start="3/1/2011" --end="3/31/2011"' ), 
    'aliases' => array( 'auc' ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function allocate_unallocated_contribs_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:allocate-unallocated-contribs':
      return dt( "Finds missing and otherwise unallocated transactions and aggregates the contrib amounts by country." );
  }
}

/**
 * Fires the 'contribution_audit_allocate_unallocated_contribs' method in the contribution_audit.
 */
function drush_allocate_unallocated_contribs() {
  $start_date = drush_get_option( 'start' );
  $end_date = drush_get_option( 'end' );
  watchdog( 'contribution_audit_drush', 'Preparing to allocate unallocated contribs for timeframe between @start and @end', array('@start'=>$start_date, '@end'=>$end_date ));
  $allocated = array();
  
  // for memory purposes, we don't want startdate/enddate to be more than 7 days apart, this should be configurable.
  while ( strtotime( $start_date . " 00:00:00" ) < strtotime( $end_date . " 23:59:59" )) {
    $this_end_date = date( 'Y-m-d', strtotime( '+7 days', strtotime( $start_date )));
    // if our increment goes beyond the original end date, reset it back to the original end date
    if ( strtotime( $this_end_date ) > strtotime( $end_date )) {
      $this_end_date = $end_date;
    }
    
    watchdog( 'contribution_audit_drush', 'Preparing to invoke contribution_audit allocate_unallocated_contribs() for timeframe between @start and @end', array('@start'=>$start_date, '@end'=>$end_date ));
    
    $this_allocated = module_invoke( 'contribution_audit', 'allocate_unallocated_contribs', $start_date, $this_end_date );
    allocate_unallocated_contribs_merge( $allocated, $this_allocated );

    // move our start date up by 8 days for the next batch
    $start_date = date( 'Y-m-d', strtotime( '+8 days', strtotime( $start_date )));
  }
  
  if ( !count($allocated)) {
    print "All contributions present and allocated.\n";
  } else {
    foreach( $allocated as $key => $val ) {
      print "'$key','" . $val['amnt'] . "','" . $val['count'] . "'\n";
    }
  }
}

function allocate_unallocated_contribs_merge( &$allocated, $local_allocated ) {
  foreach ( $local_allocated as $country => $data ) {
    if ( !in_array( $country, array_keys( $allocated ))) {
      $allocated[$country] = $local_allocated[$country];
    } else {
      $allocated[ $country ][ 'amnt' ] += $local_allocated[ $country ][ 'amnt' ];
      $allocated[ $country ][ 'count' ] += $local_allocated[ $country ][ 'count' ];
    }
  }
}

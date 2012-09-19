<?php
/**
 * @file recurring_globalcollect.drush.inc
 * Process recurring payments to GlobalCollect
 * @author Jeremy Postlethwaite <jpostlethwaite@wikimedia.org>
 */

/**
 * Implementation of hook_drush_command()
 */
function recurring_globalcollect_drush_command() {
  $items = array();

  $items['recurring-globalcollect'] = array(
    'description' => 'Process recurring payments through GlobalCollect. ',
    'examples' => array( 
      'drush recurring-globalcollect'       => '# Process recurring payments',
      'drush rg --batch=10'                        => '# Process up to 10 contributions.',
      'drush rg --date=2011-12-31'                 => '# Specify a date to process. By default, today will be processed.',
      'drush rg --date=yesterday'                  => '# Run will all payments that were due yesterday, but were not processed.',
      'drush rg --url=true'        => '# TESTING: Always return a successful response from Global Collect',
      'drush rg --url=false'       => '# TESTING: Always return a failed response from Global Collect (404)',
      'drush rg --url=404'         => '# TESTING: Always return a 404 response from Global Collect',
      'drush rg --url=504'         => '# TESTING: Always return a 504 response from Global Collect',
      'drush rg --url=404.php'     => '# TESTING: Send the request to a url relative to to tests/resources/responses/',
      "drush rg --url='https://example.org/'"     => '# TESTING: Send the request to a full url.',
    ),
    'options' => array(
      'batch' => "Number of contributions to process. Maximum is 100. If set to zero, nothing will be done.",
      'date' => 'Date to process payments for, defaults to today. Must not be in the future. The date will be parsed with strtotime().', // TODO mention sliding window logic
      'url' => 'URL to send GlobalCollect API requests to. Defaults to the globalcollect_url setting. Allowed values: true | false | 404 | 504 | some-relative-url | https://example.org',
    ),
    'aliases' => array( 'rg' ),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function recurring_globalcollect_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:recurring-globalcollect':
      return dt( 'Process recurring payments to GlobalCollect' );
  }
}

/**
 * Fires the 'batch_process' method in the recurring_globalcollect module.
 *
 */
function drush_recurring_globalcollect() {

  $date = drush_get_option('date');
  $url = drush_get_option('url');
  $batch = (integer) drush_get_option('batch', variable_get('recurring_globalcollect_batch', 0));
  
  $options = array();

  // Set the date
  $options['date'] = drush_recurring_globalcollect_parse_date($date);
 
  // Set the url
  if ( !is_null($url)) {
    $options['url'] = $url;
  }
 
  // Set the number of contributions to batch
  $options['batch'] = $batch;
  
  module_invoke( 'recurring_globalcollect', 'batch_process', $options );

  // Outputting errors again so they can be picked up by Jenkins.
  $errors = drush_get_error_log();
  if (!empty($errors)){
    echo "\n***ERRORS***";
    foreach($errors as $error=>$msgarray){
      echo "\n$error: ";
      foreach ($msgarray as $count=>$message){
        echo "\n    $message";
      }
    }
    echo "\n\n";
    exit(drush_get_error());
  }
}

/**
 * drush_recurring_globalcollect_validate
 * 
 * This function is called magically from within Drush
 *
 * Numeric values for batch will be converted to an integer:
 * - --batch=3.14 => --batch=3
 *
 * Options are not required to run `drush rg`
 * Settings will be picked up in the module.
 *
 * If --batch is not set it will go to the default settings: variable_get('recurring_globalcollect_batch', 0)
 *
 * If --date is not set it will use today: date('Y-m-d')
 *
 * If --process is not set it will use next_sched_contribution
 *
 * validate:
 * - Making sure the date is not in the future is done in drush_recurring_globalcollect_parse_date()
 * - Make sure batch is a postive integer
 * - Make sure process is either next_sched_contribution | failure_retry_date
 * - Make sure settings have been saved:
 *   - failures_before_cancellation
 */
function drush_recurring_globalcollect_validate() {

  $link_to_settings = url('admin/settings/recurring_globalcollect', array('absolute'=>true));

  $batch = drush_get_option('batch', variable_get('recurring_globalcollect_batch', 0));
  $date = drush_get_option('date');
  
  $batch_max = (integer) variable_get('recurring_globalcollect_batch_max', 0);

  /**
   * Validate batch:
   * - must be numeric
   * - if zero, exit
   * - must be positive
   * - must be less than batch_max
   */
  if (!is_numeric($batch)) {
    $message = 'You did not specify a number. You must specify either'
         . ' zero or a postive integer less than ' . $batch_max
         . '. You specifed [' . (string) $batch . '].';
    drush_set_error('BATCHING', dt($message));
    return false;
  }
  
  // $batch is numeric, convert it to an integer for further testing.
  $batch = (integer) $batch;
  
  if ($batch < 1) {
    
    $message = 'Batching is disabled: $batch = "' . $batch . '"';
    $link = l('Edit recurring GlobalCollect settings', 'admin/settings/recurring_globalcollect');
    watchdog('recurring_globalcollect', $message, array(), WATCHDOG_WARNING, $link);
    return false;         
  }
  
  if ($batch < 0) {

    $message = 'You specified a negative number. You must specify either'
      . ' zero or a postive integer less than ' . $batch_max . '. You'
      . ' specifed [' . $batch . '].';
    drush_set_error('BATCHING', dt($message));
    return false;
  }
  
  if ($batch > $batch_max) {
    $message = 'You are attempting to batch ' . $batch .' payments, which'
      . ' is more than the maximum allowed: ' . $batch_max .'. Either batch'
      . ' less payments or increase the maximum. You may change settings here: '
      . $link_to_settings;
    drush_set_error('BATCHING', dt($message));
    return false;
  }

  /**
   * Validate settings:
   * - failures_before_cancellation
   */
  $failures_before_cancellation = (integer) variable_get('recurring_globalcollect_failures_before_cancellation', 0);
  
  if ( $failures_before_cancellation < 1 ) {
    $message = 'The value in settings, "Failures before subscription is'
             . ' cancelled" must be a postive integer. You specifed ['
             . (string) $failures_before_cancellation . '].';
    drush_set_error('RG_SETTINGS', dt($message));
    return false;
  }

  // Nothing else to validate at this point
  return true;
}

/**
 * Parses the date for processing
 *
 * @param string	$date	The date will be parsed with strtotime($date).
 *
 * The default date is today.
 *
 * @return			Returns the date with the format: 'Y-m-d'
 */
function drush_recurring_globalcollect_parse_date($date) {
  
  if (!empty($date)) {
    $now_stamp = time();
    $now = date('Y-m-d', $now_stamp);
    $date_stamp = strtotime($date);
    
    // Set date from stamp so we have the proper format expected by the module.
    $date = date('Y-m-d', $date_stamp);
    
    if ($date_stamp > $now_stamp) {
      $message = 'The date you entered [' . $date . '] is being parsed as [' . $date . ']. The current date is: [' . $now . ']. You are not allowed to specify dates in the future.';
      drush_set_error('FUTURE_DATE', dt($message));
      return false;
    }
  }
  
  return $date;
	
}


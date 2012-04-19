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
    'description' => 'Process recurring payments to GlobalCollect. '
                      . 'There is only parameter for this command, url. '
                      . 'This is primarily for testing only.',
    'examples' => array( 
      'drush recurring-globalcollect'       => '# Process recurring payments',
      'drush rg --batch=10'                        => '# Batch process up to 10 contributions.',
      'drush rg --date=2011-12-31'                 => '# Specify a date to process. By default, today will be processed.',
      'drush rg --date=yesterday'                  => '# Run will all payments that were due yesterday, but were not processed.',
      'drush rg --process=next_sched_contribution' => '# By default next_sched_contribution will be processed.',
      'drush rg --process=failure_retry_date'      => '# Retry all of the failed payments',
      'drush rg --url=true'        => '# TESTING: Always return a successful response from Global Collect',
      'drush rg --url=false'       => '# TESTING: Always return a failed response from Global Collect (404)',
      'drush rg --url=404'         => '# TESTING: Always return a 404 response from Global Collect',
      'drush rg --url=504'         => '# TESTING: Always return a 504 response from Global Collect',
      'drush rg --url=404.php'     => '# TESTING: Send the request to a url relative to to tests/resources/responses/',
      "drush rg --url='https://example.org/'"     => '# TESTING: Send the request to a full url.',
    ),
    'options' => array(
      'batch' => 'Allowed parameters: 0 <= $batch <= 100 | A value of zero disables batch processing. 100 is the maximum allowed for processing.',
      'date' => 'Expected format: YYYY-MM-DD | Specify a date, that is not in the future. The date will be parsed with strtotime().',
      'process' => 'Defaults to next_sched_contribution. Allowed parameters: next_sched_contribution | failure_retry_date',
      'url' => 'Allowed parameters: true | false | 404 | 504 | some-relative-url | https://example.org',
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
  $process = drush_get_option('process');
  $url = drush_get_option('url');
  $batch = drush_get_option('batch', null);
  
  $options = array();
 
  // Set the date
  if ( !is_null($date)) {
    
    // Sanitize the date
    $date_stamp = strtotime($date);
    
    // Reparse date
    $date_reparsed = date('Y-m-d', $date_stamp);
    
    $options['date'] = $date_reparsed;
  }
  
  // Set the process
  if ( !is_null($process)) {
    $options['process'] = $process;
  }
 
  // Set the url
  if ( !is_null($url)) {
    $options['url'] = $url;
  }
 
  // Set the number of contributions to batch
  if ( !is_null($batch)) {
    $options['batch'] = $batch;
  }
  
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
 * Numeric values for batch will be converted to an integer:
 * - --batch=3.14 => --batch=3
 *
 * Options are not required to run `drush rg`
 * Settings will be picked up in the module.
 *
 * If --batch is not set it will go to the default settings: variable_get('recurring_globalcollect_batch', 0)
 *
 * If --date is not set it will go to the default settings: date('Y-m-d')
 *
 * If --process is not set it will go to the default settings: next_sched_contribution
 *
 * validate:
 * - Make sure the date is not in the future
 * - Make sure batch is a postive integer
 * - Make sure process is either next_sched_contribution | failure_retry_date
 */
function drush_recurring_globalcollect_validate() {
          

  $link_to_settings = url('admin/settings/recurring_globalcollect', array('absolute'=>true));

  $batch = drush_get_option('batch', 0);
  $date = drush_get_option('date');
  $process = drush_get_option('process');
  
  $batch_max = (integer) variable_get('recurring_globalcollect_batch_max', 0);

  $process_allowed_options = array(
      'next_sched_contribution',
      'failure_retry_date',
  );
  
  // Validate batch
  if (!is_null($batch)) {
      
    if (is_bool($batch)) {
        $batch = (integer) $batch;
    }
    
    if (!is_numeric($batch)) {
      $message = 'You did not specify a number. You must specify either'
               . ' zero or a postive integer less than ' . $batch_max
               . '. You specifed [' . (string) $batch . '].';
      drush_set_error('BATCHING', dt($message));
      return false;
    }
    
    // $batch is numeric, convert it to an integer.
    $batch = (integer) $batch;
    
    
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
        . ' less payments or increase the maximum. You may change settings here: ' . $link_to_settings;
      drush_set_error('BATCHING', dt($message));
      return false;
    }
      
  }

  // Validate date
  if (!empty($date)) {
    $now_stamp = time();
    $now = date('Y-m-d', $now_stamp);
    $date_stamp = strtotime($date);
    
    // Reparse date
    $date_reparsed = date('Y-m-d', $date_stamp);
    
    if ($date_stamp > $now_stamp) {
      $message = 'The date you entered [' . $date . '] is being parsed as [' . $date_reparsed . ']. The current date is: [' . $now . ']. You are not allowed to specify dates in the future.';
      drush_set_error('INVALID_DATE', dt($message));
      return false;
    }
  }
  
  // Validate process
  if (!is_null($process)) {
      
      if (!in_array($process, $process_allowed_options)) {
        $message = 'You must specify a valid process option. You specifed [' . $process . ']. The acceptable process options are as listed: [' . implode(', ', $process_allowed_options) . '].';
        drush_set_error('INVALID_PROCESS', dt($message));
        return false;
      }
  }
  
  // Nothing else to validate at this point
  return true;
}

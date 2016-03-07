<?php
/**
 * @file queue_performance.drush.inc
 *  Test the performance of pushing the queue into CiviCRM.
 *
 * This is intended to be run before and after changes anticipated to affect performance.
 */

/**
 * Implementation of hook_drush_command()
 */
function queue_performance_drush_command() {
  $items = array();

  $items['profile_donation'] = array(
    'description' =>
      'Imports a predefined set of data for the purposes of timing it, using queue2civicrm donation processing',
    'arguments' => array(
      'number' => 'How many items to process',
      'context' => 'Message to record in the output log. E.g Staging: profile pre enabling logging',
    ),
    'required-arguments' => true,
    'examples' => array('drush qperf-d 20 "Staging:pre-upgrade test"' => '# process the file'),
    'aliases' => array('qperf-d'),
  );

  return $items;
}

/**
 * Implementation of hook_drush_help()
 */
function queue_performance_drush_help( $section ) {
  switch ( $section ) {
    case 'drush:profile_donation':
      return dt("Imports a proscribed set of data and times it. Contribution tracking updates are omitted with CiviCRM processing the focus.");
  }
}

/**
 * Run the data through an iteration.
 *
 * @param int $limit
 * @param string $message
 *
 * @throws \Exception
 */
function drush_queue_performance_profile_donation($limit, $context) {
    if ($limit > 4000) {
      throw new Exception('Not enough data in same data set');
    }
    $sourceDataFile = __DIR__ . '/tests/data/donation_performance_profile_data.json';
    $outputFile = __DIR__ . '/tests/logs/perf.log';
    drush_print("Processing input file tests/data/donation_performance_test_data.json and feeding to queue2civicrm_import.");
    drush_print('Output will be appended to ' . $outputFile . ' - committing this will provide snapshots over time');

    $messages = json_decode(file_get_contents($sourceDataFile), true);
    if (!is_array( $messages)) {
      throw new Exception("Error decoding JSON." . $sourceDataFile);
    }
    $messages = array_slice($messages, 0, $limit);
    civicrm_initialize();
    $checkSQL = "SELECT count(*) FROM wmf_contribution_extra WHERE gateway = %1 AND gateway_txn_id = %2";
    foreach($messages as $index => $msg) {
      $result = CRM_Core_DAO::singleValueQuery($checkSQL,
        array(1 => array($msg['gateway'], 'String'), 2 => array($msg['gateway_txn_id'], 'String')
      ));
      while ($result ) {
        $messages[$index]['gateway_txn_id'] = rand(0, 9999999999999);
        $result = CRM_Core_DAO::singleValueQuery($checkSQL,
          array(1 => array($messages[$index]['gateway'], 'String'), 2 => array($messages[$index]['gateway_txn_id'], 'String')
        ));
      }
      // We are only testing the performance of this interaction with CiviCRM so we can skip this part for now.
      // If we don't we get messy output, so if we want to start checking this performance we need to ensure the
      // ids exist.
      unset($messages[$index]['contribution_tracking_id']);
    }
    $outputFileResource = fopen($outputFile, 'a');

    fwrite($outputFileResource, date('Y-m-d H:i:s') . t(" Starting donation queue performance profile for @limit donations.\n Context is : @context \n", array(
      '@limit' => $limit,
      '@context' => $context,
    )));

    $now = strtotime('now');
    foreach ($messages as $msg) {
      module_invoke('queue2civicrm', 'import', $msg);
    }
    $timeTaken = strtotime('now') - $now;
    drush_print(t("Processed @limit messages in $timeTaken second(s)", array('@limit' => $limit)));

    fwrite($outputFileResource, date('Y-m-d H:i:s') . t(" Donation queue performance profile took $timeTaken second(s) for @limit donations.\n Context is : @context \n", array(
      '@limit' => $limit,
      '@context' => $context,
    )));
    $logMessage = "Average performance is " . round(($limit / $timeTaken) * 60) . " per minute.\n";
    drush_print($logMessage);
    fwrite($outputFileResource, $logMessage);
    fclose($outputFileResource);

}

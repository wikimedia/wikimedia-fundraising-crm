<?php

function recurring_globalcollect_restart_missed_drush_command() {
    $batch_max = intval(variable_get('recurring_globalcollect_batch_max', 100));
    $normal_grace = intval(variable_get('recurring_globalcollect_run_missed_days', 25));
    $items = array();

    $items['recurring-globalcollect-restart-missed'] = array(
        'description' => 'Restart recurring GlobalCollect payments which are not recurring but have no failures.',
        'options' => array(
            'batch' => "Number of contributions to process. Maximum is $batch_max. If set to zero, nothing will be done.",
            'grace' => "Number of days after which the contribution is considered stale and needs restarting. Default is $normal_grace days.",
            'limit' => 'Number of days beyond which the donation is too old to be restarted. Default 365.',
        )
    );

    return $items;
}

/**
 * Fires the 'batch_process' method in the recurring_globalcollect module.
 *
 */
function drush_recurring_globalcollect_restart_missed() {
    $batch_size = intval(drush_get_option('batch', variable_get('recurring_globalcollect_batch_max', 100)));
    $grace = intval(drush_get_option('grace', variable_get('recurring_globalcollect_run_missed_days', 25)));
    $limit = intval(drush_get_option('limit', 365));

    /*
     * Donations which 'just stopped' and did not have a scheduled recurring attempt
     */
    watchdog('recurring_globalcollect', 'Attempting to restart up to ' . $batch_size . ' recurring contribution(s).');
    $contribution_batch = rgcrm_get_contributions( $limit, $grace, $batch_size );
    watchdog(
        'recurring_globalcollect',
        'Query returned @count messages to process',
        array('@count' => count($contribution_batch))
    );
    $result = recurring_globalcollect_batch_charge(
        $contribution_batch,
        array( 'contribution_tags' => array( 'RecurringRestarted' ) )
    );
    $processed = count($result['succeeded']) + count($result['failed']);
    watchdog(
        'recurring_globalcollect',
        'Attempted to restart :processed missed contribution(s). :failed failed to restart',
        array('processed' => $processed, ':failed' => count($result['failed']))
    );

    /*
     * Donations which had a recurring attempt but failed and then missed their retry date
     */
    watchdog('recurring_globalcollect', 'Attempting to restart up to ' . $batch_size . ' abandoned contribution(s).');
    try {
        $contribution_batch = recurring_globalcollect_get_failure_retry_batch( $batch_size, 'now', $limit );
    } catch(Exception $ex) {
        wmf_common_failmail('recurring_globalcollect', $ex);
    }
    watchdog(
        'recurring_globalcollect',
        'Query returned @count messages to process',
        array('@count' => count($contribution_batch))
    );
    $result = recurring_globalcollect_batch_charge(
        $contribution_batch,
        array( 'contribution_tags' => array( 'RecurringRestarted' ) )
    );

    $processed = count($result['succeeded']) + count($result['failed']);
    watchdog(
        'recurring_globalcollect',
        'Attempted to restart :processed contribution(s). :failed failed to restart',
        array('processed' => $processed, ':failed' => count($result['failed']))
    );

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

function rgcrm_get_contributions( $limit, $grace, $batch ) {
    $earliest = date('Y-m-d 00:00:00', time() - ( $limit * 60 * 60 * 24 ));
    $latest = date('Y-m-d 23:59:59', time() - ( $grace * 60 * 60 * 24 ));

    $start_day_of_month = date('j');
    $end_day_of_month = (date('t') == $start_day_of_month ) ? 31 : $start_day_of_month;

    $contribution_status_id = civicrm_api_contribution_status('Completed');

    watchdog(
        'recurring_globalcollect',
        'Looking for contributions to restart in timeframe @min -> @max; recurring on day of month @ds -> @de ',
        array('@min' => $earliest, '@max' => $latest, '@ds' => $start_day_of_month, '@de' => $end_day_of_month )
    );

    $query = <<<EOS
SELECT * FROM civicrm_contribution_recur
WHERE
    civicrm_contribution_recur.next_sched_contribution BETWEEN :start AND :end
    AND civicrm_contribution_recur.trxn_id LIKE 'RECURRING GLOBALCOLLECT%'
    AND ( civicrm_contribution_recur.end_date IS NULL )
    AND civicrm_contribution_recur.contribution_status_id = :status_id
    AND civicrm_contribution_recur.cycle_day BETWEEN :ds AND :de
    AND civicrm_contribution_recur.frequency_unit = 'month'
LIMIT :batch
EOS;

    $dbs = wmf_civicrm_get_dbs();
    $dbs->push( 'civicrm' );

    $query = str_replace( ':batch', intval( $batch ), $query ); // Because apparently PDO will replace this as a string
    $res = db_query( $query, array(
        ':start' => $earliest,
        ':end' => $latest,
        ':ds' => $start_day_of_month,
        ':de' => $end_day_of_month,
        ':status_id' => $contribution_status_id
    ) );

    return $res->fetchAll();
}

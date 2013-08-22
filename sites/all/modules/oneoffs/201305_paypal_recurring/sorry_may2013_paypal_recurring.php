<?php

use wmf_communication\Job;
use wmf_communication\Recipient;

/**
 * Create a mailing job to apologize for the May 2013 Paypal recurring hole.
 *
 * See https://mingle.corp.wikimedia.org/projects/fundraiser_2012/cards/986
 */
function sorry_may2013_paypal_recurring_build_job() {
    $dbs = module_invoke( 'wmf_civicrm', 'get_dbs' );

    $dbs->push( 'civicrm' );
    $result = db_query( "
SELECT
    cc.id AS contribution_id,
    cc.source AS amount,
    cc.receive_date AS date,
    cc.contact_id,
    ce.email
FROM civicrm_contribution cc
LEFT JOIN civicrm_email ce ON ce.contact_id = cc.contact_id
LEFT JOIN civicrm_address ca ON ca.contact_id = cc.contact_id
LEFT JOIN civicrm_country cco ON ca.country_id = cco.id
WHERE
    cc.thankyou_date IS NULL
    AND cc.trxn_id LIKE 'RECURRING PAYPAL%'
    AND cco.iso_code = 'US'
    AND cc.receive_date BETWEEN '2013-02-01' AND '2013-05-01'
    AND ce.is_primary
GROUP BY
    cc.id
ORDER BY
    cc.receive_date ASC
;
" );
    $contributions = $result->fetchAllAssoc( 'contribution_id', PDO::FETCH_ASSOC );

    if ( !$contributions ) {
        drush_print( "No matching records found! Aborting." );
        return;
    }

    $byContact = array();
    foreach ( $contributions as $row ) {
        $byContact[$row['email']][] = $row;
    }

    $dbs->push( 'default' );

    $job = Job::create( 'SorryRecurringTemplate' );

    foreach ( $byContact as $contactId => $contributions ) {
        Recipient::create(
            $job->getId(),
            $contributions[0]['contact_id'],
            array(
                'contributions' => $contributions,
            )
        );
    }

    drush_print( "Built mailing job. Run using 'drush wmf-send-letters {$job->getId()}'" );
}

function sorry_may2013_paypal_recurring_mark_thanked() {
    $job_ran_date = '2013-08-14 00:00:00';

    $query = db_select( 'wmf_communication_recipient', 'r' );
    $query->join( 'wmf_communication_job', 'j', 'r.job_id = j.id' );
    $query->addField( 'r', 'vars' );
    $query->condition( 'j.template_class', 'SorryRecurringTemplate' )
        ->condition( 'r.status', 'successful' );
    $result = $query->execute();

    $civi = civicrm_api_classapi();

    while ( $vars = json_decode( $result->fetchField() ) ) {
        foreach ( $vars->contributions as $contribution ) {
            $success = $civi->Contribution->Create( array(
                'id' => $contribution->contribution_id,
                'thankyou_date' => $job_ran_date,

                'version' => '3',
            ) );
            if ( !$success ) {
                throw new Exception( "Failed to update contribution: " . $civi->errorMsg() );
            }
        }
    }
}

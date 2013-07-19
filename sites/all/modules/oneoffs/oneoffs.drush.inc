<?php

/**
 * Create a mailing job to apologize for the May 2013 Paypal recurring hole.
 *
 * See https://mingle.corp.wikimedia.org/projects/fundraiser_2012/cards/986
 */
function oneoffs_drush_command() {
    $items = array(
        'oneoffs-sorry-may2013-build-job' => array(
            'description' => 'Compile a list of people affected',
        ),
    );

    return $items;
}

function drush_oneoffs_sorry_may2013_build_job() {
    require_once '201305_paypal_recurring/sorry_may2013_paypal_recurring.php';
    sorry_may2013_paypal_recurring_build_job();
}

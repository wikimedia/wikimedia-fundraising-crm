<?php
/**
 * @file unsub_make.drush.inc
 *
 * Generates translated versions of the unsubscription pages from the i18n files.
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

use wmf_communication\MediaWikiMessages;
use wmf_communication\Translation;

/**
 * Implementation of hook_drush_command()
 */
function unsub_make_drush_command() {
    $items = array();

    $items['unsub-make'] = array(
        'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_FULL,
        'description' => 'Generates translated versions of the unsubscription pages from the i18n files',
        'examples' => array(
            'drush unsub-make' => '# Make translations',
            'drush unsub-make --partial' => '# Make translations, including partially translated languages'
        ),
        'options' => array(
            'partial' => 'allow translations where not all of the messages have translations'
        ),
    );

    return $items;
}

/**
 * Implementation of hook_drush_help()
 *
 * @param $section
 * @return mixed
 */
function unsub_make_drush_help( $section ) {
    switch ( $section ) {
        case 'drush:unsub-make':
            return dt( "Generates translated versions of the unsubscription pages from the i18n files.\n\n" .
            "This is NOT meant to be run in production.  It should be run locally and the results committed " .
            "and code reviewed.");
    }
}

/**
 * Generates translated versions of the unsubscribe pages from the
 * DonationInterface i18n files.
 */
function drush_unsub_make() {
    $partial = drush_get_option( 'partial', false );

    $base_path = __DIR__ . '/';
    $template_path = $base_path . 'templates/'; // TODO: ensure that this path exists
    $output_path = $base_path . 'templates/i18n/'; // TODO: ensure that this path exists

    $pages = array(
        'unsubscribe-confirm',
        'unsubscribe-fail',
        'unsubscribe-success'
    );
    $messages = array(
        'donate_interface-email-unsub-title',
        'donate_interface-email-unsub-success',
        'donate_interface-email-unsub-delay',
        'donate_interface-email-unsub-confirm',
        'donate_interface-email-unsub-warning',
        'donate_interface-email-unsub-fail',
        'donate_interface-email-unsub-button'
    );

    $DImsgs = MediaWikiMessages::getInstance();
    $languages = $DImsgs->languageList();

    foreach( $languages as $lang ){
        if( $lang === "qqq" ){
            continue;
        }
        echo "$lang -> ";
        $msgCount = 0;
        foreach( $messages as $m ){
            if( $DImsgs->msgExists( $m, $lang ) ){
                $msgCount++;
            }
        }
        if( $msgCount == count( $messages ) || ( $msgCount > 0 && $partial ) ){
            echo " fully translated\n";
            foreach( $pages as $p ){
                echo "\t writing $p ... ";
                $page_str = file_get_contents( $template_path . "$p.html" );
                $replaced = Translation::replace_messages( $page_str, $lang );

                // the following is required to fix errors in the submission of
                // messages for translation
                $replaced = str_replace( "<donations@wikimedia.org>", "<a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>", $replaced );

                $outfile = fopen( $output_path . "$p.$lang.html", 'w' );
                fwrite( $outfile, $replaced );
                fclose( $outfile );
                echo "done\n";
            }
        }
        else{
            echo " partial translation ... skipping\n";
        }
    }
    $errors = drush_get_error_log();
    if ( !empty( $errors ) ){
	    echo "\n***ERRORS***";
	    foreach( $errors as $error => $msgarray ){
		    echo "\n$error: ";
		    foreach( $msgarray as $count => $message ){
			    echo "\n    $message";
		    }
	    }
	    echo "\n\n";
	    exit( drush_get_error() );
    }
}

<?php
/**
 * @file unsub_make.drush.inc
 *
 * Generates translated versions of the unsubscription pages from the i18n files.
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 * @TODO print some useful info to STDOUT
 */

/**
 * Implementation of hook_drush_command()
 */
function unsub_make_drush_command() {
    $items = array();

    $items['unsub-make'] = array(
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
    require_once drupal_get_path( 'module', 'wmf_common' ) . '/wmf_common.module';

    $partial = drush_get_option( 'partial', false );

    $base_path = drupal_get_path( 'module', 'wmf_unsubscribe' ) . '/';
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

    $DImsgs = new MessageFile( drupal_get_path( 'module', 'wmf_common' ) . '/DonationInterface/gateway_common/interface.i18n.php' );

    foreach( $DImsgs->languageList() as $lang ){
        $msgCount = 0;
        foreach( $messages as $m ){
            if( $DImsgs->msgExists( $m, $lang ) ){
                $msgCount++;
            }
        }
        if( $msgCount == count( $messages ) || ( $msgCount > 0 && $partial ) ){
            foreach( $pages as $p ){
                $page_str = file_get_contents( $template_path . "$p.html" );
                $replaced = replace_messages( $page_str, $DImsgs, $lang );

                // the following is required to fix errors in the submision of
                // messages for translation
                $replaced = str_replace( "<donations@wikimedia.org>", "<a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>", $replaced );

                $outfile = fopen( $output_path . "$p.$lang.html", 'w' );
                fwrite( $outfile, $replaced );
                fclose( $outfile );
            }
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

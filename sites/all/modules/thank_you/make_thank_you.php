<?php

/**********************************************************************
 * NOTE: THIS FILE SHOULD NOT BE RUN IN PRODUCTION                    *
 *                                                                    *
 * To generate templates, run "php make_thank_you.php" on your local  *
 * machine.  Version control will let you know what changes, if any,  *
 * were made to existing templates and what templates are new.  After *
 * review, commit the templates to the repository and then pull those *
 * into production.                                                   *
 *                                                                    *
 * It is HIGHLY recommended that you send test Thank You emails for a *
 * translation before commit them to the repository.                  *
 *                                                                    *
 **********************************************************************/

/**
 * Convert the wikitext from a saved file into a .html template file for
 * thank you emails.
 *
 * @param $source string filename of the file containing the wikitext
 * @param $out string filename to which to write the .tpl file
 * @param $lang string the language that we are rendering
 */
function wiki2html( $source, $out, $lang ){

	$source_file = file( $source, FILE_SKIP_EMPTY_LINES );

	pre_process( $source_file, $lang );
	prep_text( $source_file );
	strip_extraneous( $source_file );
	replace_tokens( $source_file, $lang );

	while( make_paragraphs( $source_file ) ){
		// do it
	}
	linewrap( $source_file, 90 );

	// we are done, output the file

	$outfile = fopen( $out, 'w' );
	for( $i=0; $i < count( $source_file ); $i++ ){
		fwrite( $outfile, $source_file[$i] . "\n");
	}
	fclose( $outfile );
}

/**
 * Now that we have created pretty HTML, strip all the tags out and make a
 * text version for the email
 *
 * @param $source string filename of the file containing the HTML
 * @param $out string filename to which to write the .tpl file
 * @param $lang string the language that we are rendering
 */
function html2text( $source, $out, $lang ){

	$source_file = file( $source, FILE_SKIP_EMPTY_LINES );

	prep_text( $source_file );
	remove_html( $source_file );
	linewrap( $source_file, 90 );

	// we are done, output the file

	$outfile = fopen( $out, 'w' );
	for( $i=0; $i < count( $source_file ); $i++ ){
		fwrite( $outfile, $source_file[$i] . "\n" );
	}
	fclose( $outfile );
}

/**
 * Attempt a few transformations
 *
 * @param $file array containing lines representing the file
 */
function prep_text( &$file ){
	for( $i=0; $i < count( $file ); $i++ ){
		if( preg_match( '/{{unsubscribe_link|raw}}/', $file[$i] ) ){
			$file[$i] = "{{unsubscribe_link|raw}}";
		}
		while( preg_match( '/\'\'\'/', $file[$i] ) ){
			$file[$i] = preg_replace( '/\'\'\'/', '<b>', $file[ $i ], 1 );
			$file[$i] = preg_replace( '/\'\'\'/', '</b>', $file[ $i ], 1 );
		}
		while( preg_match( '/\'\'/', $file[$i] ) ){
			$file[$i] = preg_replace( '/\'\'/', '<i>', $file[ $i ], 1 );
			$file[$i] = preg_replace( '/\'\'/', '</i>', $file[ $i ], 1 );
		}
	}
}

/**
 * Replace HTML tags with text
 *
 * @param $file array containing lines representing the file
 */
function remove_html( &$file ){
	for( $i=0; $i < count( $file ); $i++ ){
		$file[ $i ] = trim( $file[ $i ] );
//		$file[ $i ] = preg_replace( '/<br \/>/', "\n", $file[ $i ] );
		$file[ $i ] = preg_replace( '/<br \/>/', "", $file[ $i ] );
		$file[ $i ] = preg_replace( '/<p>/', '', $file[ $i ] );
		$file[ $i ] = preg_replace( '/<\/p>/', "", $file[ $i ] );
		$file[ $i ] = preg_replace( '/<i>/', '', $file[ $i ] );
		$file[ $i ] = preg_replace( '/<\/i>/', "", $file[ $i ] );
		$file[ $i ] = preg_replace( '/(*ANY)<div.*>/', '', $file[ $i ] );
		$file[ $i ] = preg_replace( '/<\/div>/', "", $file[ $i ] );
	}
}

/**
 * Wrap the lines at a given column for readability
 *
 * @param $file array containing lines representing the file
 * @param int $col column number at which to wrap
 */
function linewrap( &$file, $col=70 ){

	$newlines = array();

	for( $i=0; $i < count( $file ); $i++ ){

		// give some space to Twig'y things
		$file[ $i ] = preg_replace( '/{%/', "\n{%", $file[ $i ] );

		// take into account \n's already present
		$lines = explode( "\n", $file[$i] );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if( preg_match( '/{{contribution.contribution_source}}/', $line ) ){
				// skip this line, the variables made it too long and it looks funny
				$newlines[] = $line;
				continue;
			}
			if( preg_match( '/{{unsubscribe_link|raw}}/', $line ) ){
				// skip this line, the variables made it too long and it looks funny
				$newlines[] = $line;
				continue;
			}

			$newlines = array_merge( $newlines, explode( "\n", wordwrap( $line, $col, "\n" ) ) );
		}
	}

	$file = $newlines;
}

/**
 * Do things to fix stupid oversights before translation
 *
 * @param $file array containing lines representing the file
 * @param $lang string the language that we are rendering
 */
function pre_process( &$file, $lang ){
    $before = array();
    $after = array();
    $done = true;

    // add the html for the containing box
    for( $i=0; $i < count( $file ); $i++ ){
        if( preg_match( '/<!--this will be shown in a box-->/', $file[$i] ) ){
            $done = false;
            $after[] = "<div style=\"padding:0 10px 5px 10px; border:1px solid black;\">\n";
            // strip the <br />, we want to use a <p>
            $file[ $i ] = preg_replace( '/<br \/>/', '', $file[ $i ] );
            // make it italic, this will cause the addition of <p> to fail, so do it now
            $file[ $i ] = "<p><i>" . rtrim( $file[ $i ], "\n" ) . "</i></p>\n";
        }
        if( preg_match( '/\[amount\]/', $file[$i] ) ){
            $file[$i] = rtrim( $file[$i], "\n" ) . " {% if recurring %}{% include 'recurring/$lang.html' ignore missing %}{% endif %}\n";
        }

        if( $done ){
            $before[] = $file[$i];
        } else{
            $after[] = $file[$i];
        }

        if( preg_match( '/\[unsub link\]/', $file[$i] ) ){
            $after[] = "</div>";
            $done = true;
        }
    }
    $file = array_merge( $before, $after );
}

/**
 * Strip comments and other things that are irrelevant to the rendering
 *
 * @param $file array containing lines representing the file
 */
function strip_extraneous( &$file ){
    $pattern = array(
        '/<!--(?:(?!-->).)*-->/', // html comments
        '/{{(?:(?!}}).)*}}/', // MediaWiki template calls
    );
    $replace = '';

    for( $i=0; $i < count( $file ); $i++ ){
        $file[ $i ] = preg_replace( $pattern, $replace, $file[ $i ] );
    }
}

/**
 * Replace tokens in the translation with the correct template parameters
 *
 * @param $file array containing lines representing the file
 * @param $lang string the language that we are rendering
 */
function replace_tokens( &$file, $lang ){
	require_once '../wmf_common/MessageFile.php';

	$di_i18n = new MessageFile( '../wmf_common/DonationInterface/gateway_common/interface.i18n.php' );

    $pattern = array(
        '/\[first name\]/',
        '/\[date\]/',
        '/\[amount\]/',
        '/\[unsub link\]/'
    );
    $replace = array(
        '{{contact.first_name}}',
        '{{contribution.receive_date}}',
        '{{contribution.contribution_source}}',
        '<a style="padding-left: 25px;" href="{{unsubscribe_link|raw}}">' . $di_i18n->getMsg( 'donate_interface-email-unsub-button', $lang ) . '</a>'
    );

    for( $i=0; $i < count( $file ); $i++ ){
        $file[ $i ] = preg_replace( $pattern, $replace, $file[ $i ] );
    }
}

/**
 * Make HTML paragraphs from plaintext
 *
 * @param $file array containing lines representing the file
 *
 * @return bool true if changes were made, false otherwise
 */
function make_paragraphs( &$file ){
    $changes = false;

    for( $i=0; $i < count( $file ); $i++ ){
        if( trim($file[$i]) == "" ){
            unset( $file[$i] );

            $changes = true;
        }
    }
    $file = array_merge( $file, array() ); // re-index the array after deletes

    for( $i=0; $i < count( $file ); $i++ ){
        if( preg_match( '/<br \/>$/', $file[$i] ) ){
            if( $i + 1 < count( $file ) ){
                $file[ $i ] .= $file[ $i+1 ];
                $file[ $i+1 ] = '';

                $changes = true;
            }
        }
    }

    if( !$changes ){
        for( $i=0; $i < count( $file ); $i++ ){
            // skip lines that look like they start with an html tag
            if( !preg_match( '/^</', $file[ $i ] ) ){
                $file[ $i ] = "<p>" . rtrim( $file[ $i ], "\n" ) . "</p>\n";
            }
        }
    }

    return $changes;
}


/**
 * This function builds a valid URL by joining the base URL with a
 * valid query string that is generated from the passed key, value pairs.
 *
 * @param $base_url string the base URL
 * @param $params array an array of key/value pairs for the querystring
 * @return string the resulting URL
 */
function build_query( $base_url, $params ){
    $url = $base_url . '?';

    foreach( $params as $p => $v ){
        $url .= $p . '=';
        if( is_array( $v ) ){
            $v = implode( '|', $v );
        }
        $url .= $v . '&';
    }
    $url = rtrim( $url, '&' );

    return $url;
}

/**
 * This function gets a the most recent revision of the specified page in
 * the specified language.  The revision is saved if it has not been
 * previously retrieved.  The filename and the status of the revision with
 * relation to the title and language are returned.
 *
 * @param $title string The title of the page to be retrieved
 * @param $lang string The language of the page to be retrieved
 * @return array the results of the operation
 */
function get_revision( $title, $lang ){

    $base_url = "https://meta.wikimedia.org/w/api.php";
    $params = array(
        'action' => 'query',
        'prop' => 'revisions',
        'rvprop' => array(
            'ids',
            'content'
        ),
        'format' => 'json'
    );

    $url = build_query( $base_url, array_merge( $params, array( 'titles' => $title . '/' . $lang ) ) );

    $c = curl_init( $url );
    curl_setopt_array( $c, array(
        CURLOPT_HEADER => FALSE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_USERAGENT => "PHP/cURL - WMF Fundraising Translation Grabber - pgehres@wikimedia.org"
    ));

    $r = curl_exec( $c );
    curl_close( $c );

    $j = json_decode( $r, true );

    if( array_key_exists( -1, $j['query']['pages'] ) ){
        return array(
            "filename" => null,
        );
    }

    $page = array_keys( $j['query']['pages'] );
    $page = $j['query']['pages'][ $page[0] ];

    $revid = $page['revisions'][0]['revid'];
    $content = $page['revisions'][0]['*'];

    if( !is_dir( "translations/$title" ) ){
        mkdir( "translations/$title", 0755, true );
    }
    $filename = "translations/$title/$lang.$revid.wiki";

    if( file_exists( $filename ) ){
        return array(
            "filepath" => "translations/$title",
            "filename" => "$lang.$revid.wiki",
            "updated" => false,
            "new" => false,
            "revision" => $revid
        );
    }

    $outfile = fopen( $filename, 'w' );
    fwrite( $outfile, $content );
    fclose( $outfile );

    $list = glob( "translations/$title/$lang.*" );
    if( count( $list ) > 1 ){ // check > 1 since we already created the current one
        return array(
            "filepath" => "translations/$title",
            "filename" => "$lang.$revid.wiki",
            "updated" => true,
            "new" => false,
            "revision" => $revid
        );
    } else {
        return array(
            "filepath" => "translations/$title",
            "filename" => "$lang.$revid.wiki",
            "updated" => false,
            "new" => true,
            "revision" => $revid
        );
    }
}

/**
 *
 */

$titles = array(
    'Fundraising_2011/Thank_You_Mail' => array(
        // deployed during 2011
        'da','el','en','es-ar','es-es','fr','gl','it','nl','pt','vi','zh-hans',
        // not deployed during 2011
//        'ar','az','bg','bn','cs','cy','de','et','fa','fi','he','hr','hu','id',
//        'ja','ko','lt','mk','ml','ms','nn','no','or','pam','pl','pt-br','ro',
//        'ru','sk','sl','sq','sr','sv','th','tl','tr','uk','yi','zh-hant'
    ),
);

foreach( $titles as $t => $langs ){
    print "$t\n";

    foreach( $langs as $l ){
        $r = get_revision( $t, $l ) ;

        print "\t$l - ";
        if( $r['filename'] === null ){
            print "NOT FOUND\n";
        } else {
            if( $r['new'] ){
                print "NEW";
				wiki2html( $r['filepath'] . '/' . $r['filename'], "templates/html/thank_you.$l.html", $l );
				html2text( "templates/html/thank_you.$l.html", "templates/txt/thank_you.$l.txt", $l );
            } elseif( $r['updated'] ){
                print "UPDATED";
				wiki2html( $r['filepath'] . '/' . $r['filename'], "templates/html/thank_you.$l.html", $l );
				html2text( "templates/html/thank_you.$l.html", "templates/txt/thank_you.$l.txt", $l );
            } else {
                print "NO CHANGE";
            }
            print " - " . $r["revision"] . "\n";
        }
    }
}



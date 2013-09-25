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

$valid_translation_states = array(
	'ready',
	'proofread',
	'translated', // what exactly is this state?
);

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
 * TODO: document
 *
 * @param $title string The title of the page to be retrieved
 * @param $lang string The language of the page to be retrieved
 * @return array json array with the translations for the given language
 */
function get_translation( $title, $lang ){

	$base_url = "https://meta.wikimedia.org/w/api.php";
	$params = array(
		'action' => 'query',
		'list' => 'messagecollection',
		'mcgroup' => $title,
		'mclanguage' => $lang,
		'mcprop' => 'translation|properties',
		'format' => 'json'
	);

	$url = build_query( $base_url, $params );

	$c = curl_init( $url );
	curl_setopt_array( $c, array(
		CURLOPT_HEADER => FALSE,
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_USERAGENT => "PHP/cURL - WMF Fundraising Translation Grabber - fr-tech@wikimedia.org"
	));

	$r = curl_exec( $c );
	curl_close( $c );

	$j = json_decode( $r, true );

	return $j;
}

/**
 * Checks the json array to see if all translations are present and that all
 * translations meet the minimum status level for publication
 *
 * @param $json array containing the translations for a given language
 * @return boolean
 */
function check_translation( $json ){
	global $valid_translation_states;

	// check for the essential elements
	if( !array_key_exists( 'query', $json ) ||
		!array_key_exists( 'messagecollection', $json['query'] ) ||
		!array_key_exists( 'metadata', $json['query'] ) ||
		!array_key_exists( 'resultsize', $json['query']['metadata'] )
	){
		print "Missing required fields in JSON.\n";
		return false;
	}
	// this number is arbitrary to 2012's letter
	if( $json['query']['metadata']['resultsize'] != 24 ){
		print "Incorrect number of translated elements. Expected 24, got " . $json['query']['metadata']['resultsize'] . "\n";
		return false;
	}

	foreach( $json['query']['messagecollection'] as $message ){
		// check for required keys
		if( !array_key_exists( 'key', $message ) ||
			!array_key_exists( 'translation', $message ) ||
			!array_key_exists( 'properties', $message ) ||
			!array_key_exists( 'status', $message['properties'] )
		){
			print "Missing required fields in message JSON.\n";
			return false;
		}
		if( !in_array( $message['properties']['status'], $valid_translation_states ) ){
			// if this message is not in a valid state, we can't generate the full email - ABORT
			print "Message not in a valid state (" . $message["key"] . ")\n";
			return false;
		}
	}

	return true;
}

function add_helper_keys( &$json ){
	for( $i = 0; $i <  count( $json['query']['messagecollection'] ); $i++ ){
		$json['query']['messagecollection'][$i]["simplekey"] =
			end( explode( '/', $json['query']['messagecollection'][$i]["key"] ) );
	}
}

function get_message( $json, $n ){
	foreach( $json['query']['messagecollection'] as $message ){
		if( $message['simplekey'] == $n ){
			return strip_misc( $message['translation'] );
		}
	}
	return false;
}

function strip_misc( $message ){
	// strip tvars
	$message = preg_replace( '/<tvar\|((?:(?!>).)+)(?:(?!<).)+<\/>/', '\$$1', $message );
	// strip commetns
	$message = preg_replace( '/<!--((?:(?!-->).)+)-->/', '', $message );

	return $message;

}

function replace_variables_html( $message ){
	$replacements = array(
		'/\$givenname/' => '{{contact.first_name}}',
		'/\$date/' => '{{contribution.receive_date}}',
		'/\$amount/' => '{{contribution.contribution_source|l10n_currency(locale)}}',
		'/\[\$url1 ((?:(?!\]).)*)\]/' => '<a href="https://en.wikipedia.org/wiki/Wikipedia:Introduction">$1</a>',
		'/\[\$url2 ((?:(?!\]).)*)\]/' => '<a href="https://twitter.com/Wikipedia">$1</a>',
		'/\[\$url3 ((?:(?!\]).)*)\]/' => '<a href="https://identi.ca/wikipedia">$1</a>',
		'/\[\$url4 ((?:(?!\]).)*)\]/' => '<a href="https://plus.google.com/+Wikipedia/posts">$1</a>',
		'/\[\$url5 ((?:(?!\]).)*)\]/' => '<a href="https://www.facebook.com/wikipedia">$1</a>',
		'/\[\$url6 ((?:(?!\]).)*)\]/' => '<a href="https://blog.wikimedia.org">$1</a>',
		// TODO: DO WE HAVE TRANSLATIONS FOR THE ANNUAL REPORT
		'/\[\$url7 ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Annual_Report">$1</a>',
		// TODO: DO WE HAVE TRANSLATIONS FOR THE ANNUAL PLAN
		'/\[\$url8 ((?:(?!\]).)*)\]/' => '<a href="http://upload.wikimedia.org/wikipedia/foundation/4/4f/2012-13_Wikimedia_Foundation_Plan_FINAL_FOR_WEBSITE.pdf">$1</a>',
		// TODO: DO WE HAVE TRANSLATIONS FOR THE 5-YEAR, STRATEGIC PLAN
		'/\[\$url9 ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Wikimedia_Movement_Strategic_Plan_Summary">$1</a>',
		'/\[\$url10 ((?:(?!\]).)*)\]/' => '<a href="https://shop.wikimedia.org">$1</a>',
		'/\[\$url11 ((?:(?!\]).)*)\]/' => '<a style="padding-left: 25px;" href="{{unsubscribe_link|raw}}">$1</a>',
	);
	foreach( $replacements as $k => $v ){
		$message = preg_replace( $k, $v, $message );
	}
	return $message;
}

function generate_html_2012( $lang, $json, $outfilename ){
	$outfile = fopen( $outfilename, 'w' );

	// Dear ...
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 1 ) ) . "</p>\n" );
	// letter body
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 2 ) ) . "</p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 3 ) ) . "</p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 4 ) ) . "</p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 5 ) ) . "</p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 6 ) ) . "</p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 7 ) ) . "" );
	// paragraph with Wikipedia:Introduction
	if( $lang == "en" ){
		fwrite( $outfile, " " . replace_variables_html( get_message( $json, 19 ) ) . "" );
		fwrite( $outfile, " " . replace_variables_html( get_message( $json, 20 ) ) . "" );
	}
	fwrite( $outfile, "</p>\n<p>" . replace_variables_html( get_message( $json, 8 ) ) . "</p>\n" );

	// Thanks,
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 9 ) ) . "<br />\n" );
	// Sue
	fwrite( $outfile, "" . replace_variables_html( get_message( $json, 10 ) ) . "</p>\n" );
	fwrite( $outfile, "<br />\n" );

	// Sue Gardner
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 11 ) ) . "<br />\n" );
	// Executive Director
	fwrite( $outfile, "" . replace_variables_html( get_message( $json, 12 ) ) . "<br />\n" );
	// Wikimedia Foundation
	fwrite( $outfile, "" . replace_variables_html( get_message( $json, 13 ) ) . "</p>\n" );

	// receipt
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 21 ) ) . "" );
	fwrite( $outfile, "{% if recurring %}{% include 'recurring/$lang.html' ignore missing %}{% endif %}</p>\n" );

	// 501(c)(3)
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 23 ) ) . "</p>\n" );

	// social media, annual report, and shop
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 14 ) ) . "" );
	fwrite( $outfile, " " . replace_variables_html( get_message( $json, 15 ) ) . "" );
	fwrite( $outfile, " " . replace_variables_html( get_message( $json, 16 ) ) . "</p>\n" );

	// opt-out
	fwrite( $outfile, "<div style=\"padding:0 10px 5px 10px; border:1px solid black;\">\n" );
	fwrite( $outfile, "<p><i>" . replace_variables_html( get_message( $json, 17 ) ) . "</i></p>\n" );
	fwrite( $outfile, "<p>" . replace_variables_html( get_message( $json, 18 ) ) . "</p>\n" );
	fwrite( $outfile, "" . replace_variables_html( get_message( $json, 22 ) ) . "\n" );
	fwrite( $outfile, "</div>\n" );


	fclose( $outfile );
}

/**
 * Wrap the lines at a given column for readability
 *
 * @param $filename array containing lines representing the file
 * @param int $col column number at which to wrap
 */
function linewrap( $filename, $col=70 ){

	$file = file( $filename, FILE_SKIP_EMPTY_LINES );

	$newlines = array();

	for( $i=0; $i < count( $file ); $i++ ){

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

	// we are done, output the file
	$outfile = fopen( $filename, 'w' );
	for( $i=0; $i < count( $newlines ); $i++ ){
		fwrite( $outfile, $newlines[$i] );
		// if the line doesn't end in a <br />, add a \n for spacing
		if( substr( $newlines[$i], - strlen( "<br />" ) ) != "<br />" ){
			fwrite( $outfile, "\n" );
		}
	}
	fclose( $outfile );
}

/**
 *
 * @param $htmlfilename
 * @param $outfilename
 */
function generate_txt_2012( $htmlfilename, $outfilename ) {

	$html = new html2text( $htmlfilename, true );
	$outfile = fopen( $outfilename, 'w' );
	fwrite( $outfile, $html->get_text() );
	fclose( $outfile );
}

/**
 *
 */

$titles = array(
	'page-Fundraising%202012/Translation/Thank%20you%20letter' => array(
		// deployed during 2011
		'da','el','en','es-ar','es-es','fr','gl','it','nl','pt','vi','zh-hans',
		// not deployed during 2011
        'ar','az','bg','bn','cs','cy','de','et','fa','fi','he','hr','hu','id',
        'ja','ko','lt','mk','ml','ms','nn','no','or','pam','pl','pt-br','ro',
        'ru','sk','sl','sq','sr','sv','th','tl','tr','uk','yi','zh-hant'
	)
);

foreach( $titles as $t => $langs ){
	print "$t\n";

	foreach( $langs as $l ){
		print "Getting " . $l . "\n";
		$json = get_translation( $t, $l );
		if ( check_translation( $json ) ){
			add_helper_keys( $json );
			generate_html_2012( $l, $json, "templates/html/thank_you.$l.html" );
			generate_txt_2012( "templates/html/thank_you.$l.html", "templates/txt/thank_you.$l.txt" );
			linewrap( "templates/html/thank_you.$l.html", 90 );
		}
	}
}


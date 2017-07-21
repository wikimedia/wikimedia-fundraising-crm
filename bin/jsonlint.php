<?php
/**
 * Parse json files from current directory.
 *
 * Inspired by Adam Wight "LintYaml.php" from the DonationInterface.
 */

use Seld\JsonLint\JsonParser;

require_once __DIR__ . '/../vendor/autoload.php';

/** Passed to fnmatch() */
$excludes = array(
	'./vendor/**'
);

$jsonFiles = new recursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator( '.' ),
	/** Actual filter */
	function ( $cur, $key, $iterator ) {
		global $excludes;

		foreach ( $excludes as $exclude ) {
			if ( fnmatch( $exclude, $cur->getPathname() ) ) {
				return false;
			}
		}


		if ( $iterator->hasChildren() ) {
			return true;  // recurse
		}
		return 'json' === $cur->getExtension();
	}
);

$jp = new JsonParser();
$hasError = 0;
$count = 0;
foreach ( new RecursiveIteratorIterator( $jsonFiles ) as $jsonFile ) {
	$count++;
	$err = $jp->lint( file_get_contents( $jsonFile ) );
	if ( $err ) {
		$hasError++;
		fwrite( STDERR, $jsonFile . ': ' . $err->getMessage() . "\n" );
	}
}
if ( $hasError ) {
	exit(1);
} else {
	print "Linted $count json files\n";
}

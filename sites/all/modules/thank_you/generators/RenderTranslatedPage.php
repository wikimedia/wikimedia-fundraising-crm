<?php namespace thank_you\generators;

/**
 * Generator template for pulling a translated page from a mediawiki
 * installation and doing variable substitution.
 *
 * Post generation it is highly recommended that you send a test
 * mailing
 */
class RenderTranslatedPage {
	/** @var string Working title */
	protected $title = '';
	/** @var array regex => replacement string */
	protected $substitutions = array();
	/** @var string Prototype file path for downloaded content. $1 will be replaced by the language */
	protected $proto_file = '';

	/** @var string Content language of source wiki */
	protected $source_lang = 'en';   // XXX: This should probably come from querying the sites API
	/** @var string User agent to report to wiki when making API calls */
	protected $ua = 'PHP/cURL - WMF Fundraising Translation Grabber - fr-tech@wikimedia.org';
	/** @var string API URL to use on source wiki */
	protected $api_url = 'https://meta.wikimedia.org/w/api.php';

	/**
	 * @var string[] Translation states that this module will accept as acceptable
	 * to include in a composite message.
	 */
	protected $valid_translation_states = array(
		'ready',
		'proofread',
		'translated', // what exactly is this state?
	);

	public function execute() {
		watchdog(
			'make-thank-you',
			"Obtaining translations for '{$this->title}' for placement into '{$this->proto_file}'",
			null,
			WATCHDOG_INFO
		);

		$languages = $this->get_translated_languages();
		foreach( $languages as $lang ) {
			try {
				$this->check_translation( $lang );
				$page_content = $this->get_parsed_page( $lang );
				$page_content = $this->do_replacements( $page_content );
				$page_content = wordwrap( $page_content, 100 );

				// Make it nicer to read
				$page_content = str_replace( '|</p>|', "</p>\n", $page_content );

				$file = str_replace( '$1', $lang, $this->proto_file );

				if (file_put_contents( $file, $page_content )) {
					watchdog( 'make-thank-you', "$lang -- Wrote translation into $file", null, WATCHDOG_INFO );
				} else {
					watchdog( 'make-thank-you', "$lang -- Could not open $file for writing!", null, WATCHDOG_ERROR );

					// This is implicit already but I want it to be explicit. Running this
					// script means you should be watching the output anyways.
					continue;
				}
			} catch ( TranslationException $ex ) {
				watchdog( 'make-than-you', "$lang -- {$ex->getMessage()}", null, WATCHDOG_ERROR );
			}
		}
	}

	/**
	 * This function builds a valid MediaWiki API URL by joining the $base_url
	 * with a query string that is generated from the passed key, value pairs.
	 *
	 * @param $params array an array of key/value pairs for the querystring
	 * @return string the resulting URL
	 */
	protected function build_query( $params ){
		$url = $this->api_url . '?';

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
	 * Actually do the API query
	 * @param $params
	 *
	 * @return array|bool False if query failed, otherwise array
	 */
	protected function do_query( $params ) {
		$url = $this->build_query( $params );

		$c = curl_init( $url );
		curl_setopt_array( $c, array (
			CURLOPT_HEADER => False,
			CURLOPT_RETURNTRANSFER => True,
			CURLOPT_USERAGENT => $this->ua,
		) );
		$r = curl_exec( $c );
		curl_close( $c );

		return json_decode( $r, true );
	}

	/**
	 * Query the MediaWiki API for all translated variants of the working title.
	 *
	 * @throws TranslationException
	 * @return string[] ISO languages
	 */
	protected function get_translated_languages() {
		$j = $this->do_query(
			array (
				'action' => 'query',
				'list' => 'allpages',
				'aplimit' => 500,
				'apprefix' => "{$this->title}/", // The trailing slash means we get only subpages
				'format' => 'json'
			)
		);

		if ( !is_array( $j ) ||
			 !array_key_exists( 'query', $j ) ||
			 !array_key_exists( 'allpages', $j['query'] )
		) {
			throw new TranslationException(
				"Page prefix search for {$this->title} did not return a valid result"
			);
		}
		if ( array_key_exists( 'query-continue', $j ) ) {
			throw new TranslationException(
				"Page prefix search for {$this->title} has more than 500 results! I'm not programmed for this!"
			);
		}

		$languages = array( $this->source_lang );
		foreach( $j['query']['allpages'] as $pagemeta ) {
			$parts = explode( '/', $pagemeta['title'] );
			$languages[] = end( $parts );
		}

		return $languages;
	}

	/**
	 * Obtain and verify correctness of the translation segments of the
	 * working title from MediaWiki.
	 *
	 * @param string $lang Language of the translation to be retrieved
	 *
	 * @throws TranslationException if content could not be retrieved
	 * @return bool True if OK
	 */
	protected function check_translation( $lang ) {
		static $expectedParts = array();

		$j = $this->do_query(
			array (
				'action' => 'query',
				'list' => 'messagecollection',
				'mcgroup' => "page-{$this->title}",
				'mclanguage' => $lang,
				'mcprop' => array( 'translation', 'properties'),
				'format' => 'json'
			)
		);

		if ( is_array( $j ) ) {
			// Check for required base elements
			if ( !array_key_exists( 'query', $j ) ||
				 !array_key_exists( 'messagecollection', $j['query'] ) ||
				 !array_key_exists( 'metadata', $j['query'] ) ||
				 !array_key_exists( 'resultsize', $j['query']['metadata'] )
			) {
				throw new TranslationException( "Title object {$this->title}/$lang was malformed" );
			}

			// Did we get everything?
			if ( $j['query']['metadata']['remaining'] > 0 ) {
				throw new TranslationException(
					"Title object {$this->title}/$lang was not completely fetched"
				);
			}

			// Make sure we get everything that should be there
			$numParts = count( $j['query']['messagecollection'] );
			if ( $lang === $this->source_lang ) {
				$expectedParts[$this->title] = $numParts;
			} else {
				if ( !array_key_exists( $this->title, $expectedParts ) ) {
					$this->check_translation( $this->source_lang );
				}

				if ( $numParts != $expectedParts[$this->title] ) {
					throw new TranslationException(
						"Title object {$this->title}/$lang did not have the expected number of parts " .
						"($numParts / {$expectedParts[$this->title]})"
					);
				}
			}

			// Now check each part
			foreach ( $j['query']['messagecollection'] as $part ) {
				// check for required keys
				if ( !array_key_exists( 'key', $part ) ||
					 !array_key_exists( 'translation', $part ) ||
					 !array_key_exists( 'properties', $part ) ||
					 !array_key_exists( 'status', $part['properties'] )
				) {
					throw new TranslationException(
						"Title object {$this->title}/$lang - {$part['key']} was malformed and missing keys"
					);
				}
				$transState = $part['properties']['status'];
				if ( !in_array( $transState, $this->valid_translation_states ) ) {
					$subObj = explode( '/', $part['key'] );
					$subObj = end( $subObj );

					throw new TranslationException(
						"Title '{$this->title}/$lang' sub object $subObj not in a valid state: {$transState}"
					);
				}
			}

			// If we're here everything is peachy
			return true;

		} else {
			throw new TranslationException( "Title object {$this->title}/$lang had invalid JSON" );
		}
	}

	/**
	 * Obtains the parsed HTML content of the translated working title.
	 *
	 * @param $lang
	 *
	 * @return mixed
	 * @throws TranslationException
	 */
	protected function get_parsed_page( $lang ) {
		$j = $this->do_query(
			array (
				'action' => 'parse',
				'page' => ( $lang === $this->source_lang ) ? $this->title : "{$this->title}/$lang",
				'format' => 'json'
			)
		);

		if ( !is_array( $j ) ||
			 !array_key_exists( 'parse', $j ) ||
			 !array_key_exists( 'text', $j['parse'] )
		) {
			throw new TranslationException(
				"Composite title object {$this->title}/$lang was malformed"
			);
		}

		// Load the partial DOM into a document, wrapping it with <chunk> tags (so there is only
		// one top level node) and doing a multibyte conversion from whatever PHP is running under
		// into UTF-8 (so that loadXML() doesn't throw a bitch fit and fail to load)
		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = false;
		$dom->loadXML(
			'<chunk>' .
			mb_convert_encoding( html_entity_decode( $j['parse']['text']['*'] ), 'UTF-8' ) .
			'</chunk>'
		);
		$xpath = new \DOMXPath( $dom );

		// Remove comments
		foreach ( $xpath->query( '//comment()' ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		// Remove useless DOM (like the translation header)
		foreach( $xpath->query( "//*[contains(@class, 'mw-pt-languages')]" ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		// Save it, not outputting the freaking <xml> header and <chunk> tags
		$result = array();
		$dom->formatOutput = true;
		foreach( $dom->firstChild->childNodes as $node ) {
			$result[] = $dom->saveXML( $node );
		}
		return implode( "\n\n", $result );
	}

	/**
	 * Perform content replacements on the pulled body text.
	 *
	 * @param string $content The content to act upon
	 *
	 * @return string New content
	 */
	protected function do_replacements( $content ) {
		foreach( $this->substitutions as $k => $v ) {
			$content = preg_replace( $k, $v, $content );
		}
		return $content;
	}
}

class TranslationException extends \ThankYouException {};
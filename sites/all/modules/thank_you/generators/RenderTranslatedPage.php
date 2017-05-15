<?php namespace thank_you\generators;
use wmf_communication\CiviMailStore;

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

	protected $review_history;

	public function execute($wantedLangs = array()) {
		watchdog(
			'make-thank-you',
			"Obtaining translations for '{$this->title}' for placement into '{$this->proto_file}'",
			null,
			WATCHDOG_INFO
		);

		$languages = $this->get_translated_languages();
        if (count($wantedLangs) > 0) {
            $languages = array_intersect($wantedLangs, $languages);
        }

		civicrm_initialize();
		$civimail_store = new CiviMailStore();

		foreach( $languages as $lang ) {
			try {
				$published_revision = $this->get_published_revision( $lang );

				$page_content = $this->get_parsed_page( $lang, $published_revision );
				$page_content = $this->do_replacements( $page_content );

				// Make it nicer to read
				$page_content = str_replace( '|</p>|', "</p>\n", $page_content );

				// WTF: We're suddenly getting strange errors about the unknown 'endif ' tag.
				// So, strip spaces....
				// Update: This might only be a problem for the FindUnconsumedTokens twig
				// rendering, not the rendering for mailing.
				$page_content = preg_replace( '/{%[^%]*endif[^%]*%}/sm', '{%endif%}', $page_content );

				// Assert no garbage
				FindUnconsumedTokens::renderAndFindTokens( $page_content, $lang );

				$file = str_replace( '$1', $lang, $this->proto_file );
				$template_name = basename( $file );

				$template_info = array(
					'version' => 1,
					'sourceUrl' => "https://meta.wikimedia.org/w/index.php?title={$this->title}/{$lang}&oldid={$published_revision}",
					'name' => $template_name,
					'revision' => $published_revision,
				);
				$page_content = $this->add_template_info_comment( $page_content, $template_info );

				if (file_put_contents( $file, $page_content )) {
					watchdog( 'make-thank-you', "$lang -- Wrote translation into $file", null, WATCHDOG_INFO );
					$subject = thank_you_get_subject( $lang );
					$civimail_store->addMailing( 'thank_you', $template_name, $page_content, $subject, $published_revision );
				} else {
					watchdog( 'make-thank-you', "$lang -- Could not open $file for writing!", null, WATCHDOG_ERROR );

					// This is implicit already but I want it to be explicit. Running this
					// script means you should be watching the output anyways.
					continue;
				}
			} catch ( TranslationException $ex ) {
				watchdog( 'make-thank-you', "$lang -- {$ex->getMessage()}", null, WATCHDOG_INFO );
			} catch ( CiviMailingInsertException $ex ) {
				watchdog( 'make-thank-you', "Could not insert CiviMail Mailing for $lang -- {$ex->getMessage()}", null, WATCHDOG_ERROR );
			}
		}
	}

	/**
	 * Add an HTML comment with the file name and revision number to the bottom of the page
	 *
	 * @param string $page_content HTML content without comment
	 * @param array $template_info key/val pairs describing template
	 *
	 * @returns string Content with revision comment appended
	 */
	protected function add_template_info_comment( $page_content, $template_info ) {
		$info_json = htmlentities( json_encode( $template_info ), ENT_NOQUOTES | ENT_HTML401 );
		$comment = "\n\n<!-- TI_BEGIN{$info_json}TI_END -->";
		$comment = str_replace( '\/', '/', $comment );
		return $page_content . $comment;
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
	 * Determine if and when a page has been marked "published", and prepare to
	 * fetch that revision
	 *
	 * @param string $lang Language of the translation to be retrieved
	 *
	 * @throws TranslationException if content could not be retrieved
	 * @return int revision_id when page was published
	 */
	protected function get_published_revision( $lang ) {
        // No translation workflow for English, just use the most recent rev
        if ( $lang === 'en' ) {
            return $this->get_revision_at_time( null, 'en' );
        }

        $history = $this->get_page_review_history();
        $ts = null;
        // Grab the most recent publication event
        foreach ( $history as $review_event ) {
            if ( $review_event['params']['language'] === $lang ) {
                if ( $review_event['params']['new-state'] === 'published' ) {
                    $ts = $review_event['timestamp'];
                    break;
                }
            }
        }
        if ( !$ts ) {
            throw new TranslationException( "Page {$this->title} / $lang has never been published" );
        }

        // FIXME: we probably have to use pageid to survive traversal across
        // renames.  That will take an additional API request, of course, cos
        // the preceding call does not link directly to the page.
        return $this->get_revision_at_time( $ts, $lang );
	}

    protected function get_revision_at_time( $ts, $lang ) {
        $ts = preg_replace( '/[-T:Z]/', '', $ts );

        $j = $this->do_query( array(
            'action' => 'query',
            'prop' => 'revisions',
            'titles' => $this->get_translated_title( $lang ),
            'rvstart' => $ts,
            'rvlimit' => 1,
            'format' => 'json',
        ) );

        if ( is_array( $j ) ) {
            $page = array_pop( $j['query']['pages'] );
            $revision_id = $page['revisions'][0]['revid'];
            watchdog( 'make-thank-you', "Using published version {$revision_id} at {$ts} for {$lang}", NULL, WATCHDOG_INFO );
            return $revision_id;
        }
        throw new TranslationException( "Could not find page revision of {$this->title} / $lang at time {$ts}" );
    }

    protected function get_page_review_history() {
        if ( !$this->review_history ) {
            $query = array(
                'action' => 'query',
                'list' => 'logevents',
                'letype' => 'translationreview',
                'leaction' => 'translationreview/group',
                'letitle' => "Special:Translate/page-{$this->title}",
                'lelimit' => 500,
                'format' => 'json',
            );

            $j = $this->do_query( $query );

            if ( !is_array( $j ) ) {
                throw new TranslationException( "Title object {$this->title}/$lang log query returned invalid JSON" );
            }

            $this->review_history = $j['query']['logevents'];

            while ( array_key_exists( 'query-continue', $j ) ) {
                $query['lecontinue'] = $j['query-continue']['logevents']['lecontinue'];
                $j = $this->do_query( $query );

                $this->review_history = array_merge( $this->review_history, $j['query']['logevents'] );
            }
        }
        return $this->review_history;
    }

    protected function get_translated_title( $lang ) {
        return ($lang === $this->source_lang ) ? $this->title : "{$this->title}/$lang";
    }

	/**
	 * Obtains the parsed HTML content of the translated working title.
	 *
	 * @param string $lang
     * @param int $revision_id
	 *
	 * @return mixed
	 * @throws TranslationException
	 */
	protected function get_parsed_page( $lang, $revision_id ) {
		$j = $this->do_query(
			array (
				'action' => 'parse',
				'oldid' => $revision_id,
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
		$dom->loadXML( '<chunk>' . $j['parse']['text']['*'] . '</chunk>' );
		$dom->encoding = 'UTF-8';
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

<?php
namespace wmf_communication;

use \Exception;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;

/**
 * Class MessageFile replicates some of the internationalization features of
 * MediaWiki allowing usage of i18n files in the standard MediaWiki format.
 *
 * @author Peter Gehres <pgehres@wikimedia.org>
 * @license GPLv2 or later
 */
class MediaWikiMessages {
    protected $langLoader;
    protected $messages = array();
    protected $messageFiles = array();

    /**
     * Creates a new MessageFile and initializes it with the messages
     * contained in the specified file.
     *
     * @param string $msg_file filename of the file containing the messages
     * @throws Exception if the messages could not be loaded
     */
    protected function __construct() {
        // TODO: ability to configure multiple messages sources
        $di_root = variable_get( 'wmf_common_di_location', null );
        if ( !is_dir( $di_root ) ) {
            throw new Exception( "DonationInterface i18n libraries not found.  Path checked: {$di_root}" );
        }

        $this->baseMessagesDir = $di_root;

        // It used to be that messages were put into one bit array called
        // $messages. But now there might also be a shim which will load
        // messages from an i18n json file. GAH!
        $msg_file = $this->baseMessagesDir . '/gateway_common/interface.i18n.php';
        if ( is_readable( $msg_file ) ) {
            include $msg_file;
        }

        $this->messageFiles = $this->findMessageFiles();

        if ( empty( $messages ) ) {
            $this->loadLanguage( 'en' );
        } else {
            $this->messages = $messages;
        }
    }

    static public function getInstance() {
        static $singleton;
        if ( !$singleton ) {
            $singleton = new MediaWikiMessages();
        }
        return $singleton;
    }

    /**
     * Gets a message in the specified language, if it exists.  If a translation does not
     * exist, the function attempts to return the requested message in English.  If the
     * message still does not exist, the key is returned.
     *
     * TODO: proper fallback chains
     *
     * @param string $key the name of the message to retrieve
     * @param string $language language code to attempt to retrieve
     * @return string the message in the requested language, a fallback, or finally the key
     * @throws Exception if the MessageFile is not properly initialized
     */
    public function getMsg($key, $language) {
        if ( $this->messages === null ) {
            throw new Exception("MediaWikiMessages not initialized");
        }
        // if the requested translation exists, return that
        if ( $this->msgExists( $key, $language ) ) {
            return $this->messages[$language][$key];
        }
        // try a fallback
        elseif( strpos( $language, '-' ) !== false &&
            $this->msgExists( $key, substr( $language, 0, strpos( $language, '-' ) ) )
        ) {
            return $this->messages[substr( $language, 0, strpos( $language, '-' ) )][$key];
        }
        // if not, but an english version exists, return that
        elseif ( $this->msgExists( $key, 'en' ) ) {
            return $this->messages['en'][$key];
        }
        // finally, return the message key itself
        return $key;
    }

    /**
     * Determines whether or not a translation exists in the language specified for
     * the message specified.  The function does not take into account any fallback
     * languages defined for the specified language.
     *
     * @param string $key the message key for which to search
     * @param string $language the language code for which to search for a translation of the message
     * @return bool true if a translation exists for the message, false otherwise
     * @throws Exception if the MediaWikiMessages is not properly initialized
     */
    public function msgExists($key, $language) {
        if ( empty( $this->messages[$language] ) ) {
            $this->loadLanguage( $language );
        }

        return array_key_exists($language, $this->messages)
            && array_key_exists($key, $this->messages[$language]);
    }

    /**
     * Gets a list of all available languages
     *
     * @return array a list of the language codes represented in the i18n files
     * @throws Exception if the MediaWikiMessages is not properly initialized
     */
    public function languageList() {
        $languages = array();

        foreach ( $this->messageFiles as $path ) {
            if ( preg_match( "|\b([-_a-z]+)[.]json\$|", $path, $matches ) ) {
                $languages[] = $matches[1];
            }
        }

        return $languages;
    }

    protected function findMessageFiles() {
        $messageFiles = array();

        $dir_iterator = new RecursiveDirectoryIterator( $this->baseMessagesDir );
        $iterator = new RecursiveIteratorIterator( $dir_iterator, RecursiveIteratorIterator::LEAVES_ONLY );
        foreach ( $iterator as $path => $fileObject ) {
            if ( is_readable( $path ) ) {
                $messageFiles[] = $path;
            }
        }

        return $messageFiles;
    }

    function loadLanguage( $language ) {
        foreach ( $this->messageFiles as $path ) {
            if ( preg_match( "|.*\bi18n\b(?:/.*)?/{$language}[.]json\$|", $path ) ) {
                $data = json_decode( file_get_contents( $path ), true );
                foreach ( array_keys( $data ) as $key ) {
                    if ( $key === '' || $key[0] === '@' ) {
                        unset( $data[$key] );
                    }
                }

                if ( !array_key_exists( $language, $this->messages ) ) {
                    $this->messages[$language] = array();
                }
                $this->messages[$language] = array_merge( $this->messages[$language], $data );
            }
        }
    }
}

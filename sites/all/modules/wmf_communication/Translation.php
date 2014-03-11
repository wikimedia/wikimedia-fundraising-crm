<?php namespace wmf_communication;

use \Exception;

/**
 * Helper for language tag manipulation and a rudimentary MediaWiki i18n facade
 *
 * TODO: deprecate
 */
class Translation {
    /**
     * Given a specific locale, get the next most general locale
     *
     * TODO: get from LanguageTag library and refactor interface
     */
    static function next_fallback( $language ) {
        $parts = preg_split( '/[-_]/', $language );
        if ( count( $parts ) > 1 ) {
            return $parts[0];
        }
        if ( $language === 'en' ) {
            return null;
        }
        return 'en';
    }

    /**
     * Fetch a MediaWiki message translated in the DonationInterface group
     *
     * @param $key message key
     * @param $language MediaWiki language code
     *
     * @return string message contents
     *
     * TODO: No wikitext expansion?
     * TODO: accept standard language tag and convert to MW
     * TODO: generalize beyond DonationInterface
     */
    static function get_translated_message( $key, $language ) {
        require_once drupal_get_path( 'module', 'wmf_common' ) . '/MessageFile.php';

        $di_include = implode( DIRECTORY_SEPARATOR, array(
            variable_get( 'wmf_common_di_location', null ), 'gateway_common', 'interface.i18n.php'
        ) );
        if ( !file_exists( $di_include ) ) {
            throw new Exception( "DonationInterface i18n libraries not found.  Path checked: {$di_include}" );
        }

        $di_i18n = new \MessageFile( $di_include );
        do {
            $msg = $di_i18n->getMsg( $key, $language );
            if ( $msg ) {
                return $msg;
            }
            $language = self::next_fallback( $language );
        } while ( $language );
    }

    /**
     * Convert unix locale to a two-digit language code
     *
     * TODO: the cheeze
     */
    static function normalize_language_code( $code ) {
        $locale = explode( '_', $code );
        if ( count( $locale ) == 0 ) {
            // TODO: return null
            return 'en';
        }
        if ( count( $locale ) == 1 ) {
            return $code;
        }
        if ( count( $locale ) == 2 ) {
            return $locale[0];
        }
    }
}


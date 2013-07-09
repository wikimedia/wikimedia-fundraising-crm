<?php

namespace wmf_communication;

class Translation {
    //TODO: get from MediaWiki
    static function next_fallback( $language ) {
        $parts = preg_split( '/-_/', $language );
        if ( count( $parts ) > 1 ) {
            return $parts[0];
        }
        if ( $language === 'en' ) {
            return null;
        }
        return 'en';
    }

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

    static function normalize_language_code( $code ) {
        $locale = explode( '_', $code );
        if ( count( $locale ) == 0 ) {
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


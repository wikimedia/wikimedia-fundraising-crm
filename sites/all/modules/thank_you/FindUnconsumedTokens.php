<?php

class FindUnconsumedTokens {
    static function searchTokens() {
        watchdog( 'thank_you', "Searching for unconsumed tokens in thank-you pages..." );
        $ret = 0;
        $languages = thank_you_get_languages();
        foreach ( $languages as $lang ) {
            $err = FindUnconsumedTokens::searchToken( $lang );
            $ret = $err || $ret;
        }
        if ( $ret ) {
            throw new Exception( "Bad news, you have errors." );
        }
    }

    static function searchToken( $lang ) {
        // Turn on all the lights
        $params = array(
            // FIXME: name should be run through both branches
            'first_name' => 'fix',
            'last_name' => 'me',
            'recurring' => true,
            'RecurringRestarted' => true,

            'receive_date' => time(),

            'locale' => $lang,
        );
        $buf = thank_you_render( $params );

        $bad_punctuation_re = '/
            # Square brackets, but not a numbered footnote
            \[ (?: (?! \d+ \] ) . )+ \]
            |
            # Other exotic punctuation that indicates a failed token
            (
                [${}]+
                |
                # This is a hash, but is not part of an URL fragment?
                (?: (?! https?:\/\/ ) . )+ \K [#]
            )
            # Token name if we are lucky
            (?: [-a-zA-Z0-9_]+ )?
        /x';
        if ( $count = preg_match_all( $bad_punctuation_re, $buf, $matches ) ) {
            $bad_punc = implode( ', ', $matches[0] );
            watchdog( 'thank_you',
                "Found {$count} likely tokens [{$bad_punc}] in thank-you translation [{$lang}].",
                null, WATCHDOG_ERROR
            );
            return -1;
        }
    }
}

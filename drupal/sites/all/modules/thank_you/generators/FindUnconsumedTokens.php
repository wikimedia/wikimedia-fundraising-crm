<?php
namespace thank_you\generators;

use wmf_communication\Templating;

class FindUnconsumedTokens {
    /**
     * Search all locally translated languages for unconsumed tokens
     * @throws UnconsumedTokenException
     */
    static function findAllTokens() {
        watchdog( 'thank_you',
            "Searching for unconsumed tokens in thank-you pages...", NULL, WATCHDOG_INFO );
        $failure = false;
        $languages = thank_you_get_languages();
        foreach ( $languages as $lang ) {
            try {
                $err = FindUnconsumedTokens::findTokensInLanguage( $lang );
            } catch ( UnconsumedTokenException $ex ) {
                watchdog( 'thank_you', $ex->getMessage(), null, WATCHDOG_ERROR );
                $failure = true;
            }
        }
        if ( $failure ) {
            throw new UnconsumedTokenException( "Bad news, see errors above." );
        }
    }

    /**
     * Pass template parameters which will light up as many conditional
     * branches as possible, as a cheap and dirty way to get good coverage of
     * the template contents for finding unconsumed tokens.
     */
    static protected function getRandomTemplateParams( $locale ) {
        $params = array(
            // FIXME: name should be run through both nameful and anonymous
            // branches.
            'first_name' => 'fix',
            'last_name' => 'me',
            'contact_id' => 234,
            'recurring' => true,

            'currency' => 'EUR',
            'amount' => '1.23',
            'receive_date' => time(),
            'transaction_id' => '12345',

            'locale' => $locale,
            'contribution_tags' => array(
                "RecurringRestarted",
                "UnrecordedCharge",
            ),
        );
        return $params;
    }

    // TODO: refactor out of this class
    static function renderLanguage( $lang ) {
        $params = FindUnconsumedTokens::getRandomTemplateParams( $lang );
        list($buf, $subj) = thank_you_render( $params );
        return $buf;
    }

    /**
     * @throws UnconsumedTokenException
     */
    static function findTokensInLanguage( $lang ) {
        $buf = FindUnconsumedTokens::renderLanguage( $lang );
        FindUnconsumedTokens::findTokens( $buf );
    }

    /**
     * @throws UnconsumedTokenException
     */
    static function renderAndFindTokens( $template, $locale ) {
        $params = FindUnconsumedTokens::getRandomTemplateParams( $locale );
        $rendered = Templating::renderStringTemplate( $template, $params );
        FindUnconsumedTokens::findTokens( $rendered );
    }

    /**
     * Search a buffer for unconsumed tokens
     * @throws UnconsumedTokenException
     */
    static function findTokens( $buf ) {
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
            # Grab the token name for debugging, if available
            (?: [-a-zA-Z0-9_]+ )?
        /x';
        if ( $count = preg_match_all( $bad_punctuation_re, $buf, $matches ) ) {
            $bad_punc = implode( ', ', $matches[0] );
            throw new UnconsumedTokenException(
                "Found {$count} likely tokens \"{$bad_punc}\" in rendered thank-you translation." );
        }
    }
}

class UnconsumedTokenException extends TranslationException {}

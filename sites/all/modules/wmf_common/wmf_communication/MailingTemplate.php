<?php namespace wmf_communication;

/**
 * An overridable template provider, which returns the subject string and body
 * template for a given recipient's preferred language.
 */
interface IMailingTemplate {
    /**
     * @return string Subject header for the letter
     */
    function getSubject( $recipient );

    /**
     * @return Templating template for generating the letter body
     */
    function getBodyTemplate( $recipient );
}

/**
 * Most mailings will extend this class and simply define a subject key and template path.
 * Anything which will be customized on a per-recipient basis should be controlled by this
 * class or in your derivative.
 */
abstract class AbstractMailingTemplate implements IMailingTemplate {
    abstract function getTemplateDir();

    abstract function getTemplateName();

    function getFromAddress() {
        return variable_get( 'thank_you_from_address', null );
    }

    function getFromName() {
        return variable_get( 'thank_you_from_name', null );
    }

    function getSubject( $recipient ) {
        return trim( $this->getBodyTemplate( $recipient )->render( 'subject' ) );
    }

    function getBodyTemplate( $recipient ) {
        $templateParams = array(
            'name' => $recipient->getName(),
            'email' => $recipient->getEmail(),
        );
        $templateParams = array_merge( $templateParams, $recipient->getVars() );

        return new Templating(
            $this->getTemplateDir(),
            $this->getTemplateName(),
            $recipient->getLanguage(),
            $templateParams
        );
    }
}

<?php namespace wmf_communication;

/**
 * Template provider, responsible for the subject and body templates
 *
 * Ordinarily, you can extend the AbstractMailingTemplate.
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
 * Base template provider for normal mailing jobs
 *
 * Most mailings will extend this class and simply define the two abstract methods,
 * which return a subject message key and the base template name.
 *
 * Anything which will be customized on a per-recipient basis should be controlled by this
 * class (or in your subclass).
 */
abstract class AbstractMailingTemplate implements IMailingTemplate {

    /**
     * Get the root directory to search for templates
     *
     * @return string path
     */
    abstract function getTemplateDir();

    /**
     * Get the base template name
     *
     * This name is transformed into a template file path by the Templating factory.
     *
     * @return string base name, such as 'thank_you'
     */
    abstract function getTemplateName();

    /**
     * Job is sent from this email address
     *
     * @return string email address
     */
    function getFromAddress() {
        return variable_get( 'thank_you_from_address', null );
    }

    /**
     * Job is sent as this name
     *
     * @return string full name for From string
     */
    function getFromName() {
        return variable_get( 'thank_you_from_name', null );
    }

    /**
     * Get the rendered subject line
     *
     * @param Recipient $recipient the addressee
     *
     * @return string subject
     */
    function getSubject( $recipient ) {
        return trim( $this->getBodyTemplate( $recipient )->render( 'subject' ) );
    }

    /**
     * Get a template appropriate for this recipient
     *
     * Merges contact information into the template variables.
     *
     * @param Recipient $recipient the addressee
     *
     * @return Templating prepared template
     */
    function getBodyTemplate( $recipient ) {
        $templateParams = array(
            'name' => $recipient->getName(),
            'first_name' => $recipient->getFirstName(),
            'last_name' => $recipient->getLastName(),
            'email' => $recipient->getEmail(),
            'locale' => $recipient->getLanguage(),
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

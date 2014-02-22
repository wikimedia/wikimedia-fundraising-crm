<?php namespace wmf_communication;

use \Exception;

/**
 * Single-use template.
 */
class Templating {
    protected $twig;

    protected $templates_dir;
    protected $template_name;
    protected $language;
    protected $format;
    protected $template_params;

    /**
     * Prepare a template for substitution.
     *
     * This is not a persistent entity.
     *
     * TODO: factory instead of ctor
     * TODO: Reconsider ideal level at which to "prepare".
     *
     * @param string $templates_dir file path to search for the template
     * @param string $template_name base name for the desired template
     * @param string $lang_code rendering locale
     * @param array $template_params fed to the template
     * @param string $format initial rendering mode (TODO: review usage)
     */
    function __construct( $templates_dir, $template_name, $lang_code, $template_params, $format = null ) {
        // TODO: autoloaded Twig::get
        if ( !function_exists( 'wmf_common_get_twig' ) ) {
            module_load_include( 'inc', 'wmf_common', 'twig' );
        }
        $this->twig = wmf_common_get_twig( $templates_dir );
        //TODO: class TemplatingTwig ; $this->twig = WmfTwig( $templates_dir );

        $this->templates_dir = $templates_dir;
        $this->template_name = $template_name;
        if ( $lang_code === null ) {
            // default to the UI language
            global $language;
            $lang_code = $language->language;
        }
        $this->language = $lang_code;
        $this->template_params = $template_params;
        $this->format = $format;
    }

    /**
     * Load the desired template or a fallback, and render as a string
     *
     * For the parameters {template_name: thank_you, language: it, format: txt}, we will look in the path
     * ${templates_dir}/txt/thank_you.it.txt
     *
     * @param string|null $format 
     *
     * @return string
     */
    function render( $format = null )
    {
        if ( $format ) {
            $this->format = $format;
        }
        $template = $this->loadTemplate();
        if ( !$template ) {
            throw new Exception( "Cannot load template {$this->key()}" );
        }

        return $template->render( $this->template_params );
    }

    /**
     * Get a short identifier specific to a single template file
     *
     * @return string munged natural key of (template, language, format)
     */
    protected function key() {
        return "{$this->template_name} / {$this->language} . {$this->format}";
    }

    /**
     * Find the best available template file for the desired locale and load
     *
     * @return \Twig_Template
     */
    protected function loadTemplate() {
        $language = $this->language;
        do {
            // TODO: encapsulate path strategy in a function so it can be overridden
            $template = $this->loadTemplateFile( "{$this->format}/{$this->template_name}.{$language}.{$this->format}" );
            if ( $template ) {
                return $template;
            }

            watchdog( 'wmf_communication',
                "Template :key not found in language ':language', attempting next fallback...",
                array(
                    ':key' => $this->key(),
                    ':language' => $language ),
                WATCHDOG_INFO
            );
            $language = Translation::next_fallback( $language );
        } while ( $language );

        watchdog( 'wmf_communication',
            "Using universal language fallback for template :key...",
            array( ':key' => $this->key() ),
            WATCHDOG_INFO
        );
        return $this->loadTemplateFile( "{$this->format}/{$this->template_name}.{$this->format}" );
    }

    /**
     * Load a Twig template from the given filesystem path
     *
     * @param string $path absolute path, or path relative to configured Twig include dirs
     *
     * @return Twig_Template
     */
    protected function loadTemplateFile( $path ) {
        watchdog( 'wmf_communication',
            "Searching for template file at :path",
            array( ':path' => $path ),
            WATCHDOG_DEBUG
        );
        try {
            return $this->twig->loadTemplate( $path );
        } catch ( \Twig_Error_Loader $ex ) {
            // File does not exist.  pass
        }
        return null;
    }
}

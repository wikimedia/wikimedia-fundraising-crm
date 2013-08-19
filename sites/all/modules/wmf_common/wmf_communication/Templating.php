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

    function __construct( $templates_dir, $template_name, $lang_code, $template_params, $format = null ) {
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
     * Construct a path from the given parameters, load the appropriate
     * template, and render.
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

    protected function key() {
        return "{$this->template_name} / {$this->language} . {$this->format}";
    }

    protected function loadTemplate() {
        $language = $this->language;
        do {
            $template = $this->loadTemplateFile( "{$this->format}/{$this->template_name}.{$language}.{$this->format}" );
            if ( $template ) {
                return $template;
            }

            watchdog( 'wmf_communication', t( "Template :key not found in language ':language', attempting next fallback...", array( ':key' => $this->key(), ':language' => $language ) ), WATCHDOG_INFO );
            $language = Translation::next_fallback( $language );
        } while ( $language );

        watchdog( 'wmf_communication', t( "Using universal language fallback for template :key...", array( ':key' => $this->key() ) ), WATCHDOG_INFO );
        return $this->loadTemplateFile( "{$this->format}/{$this->template_name}.{$this->format}" );
    }

    protected function loadTemplateFile( $path ) {
        watchdog( 'wmf_communication',
            t( "Searching for template file at :path",
                array( ':path' => $path )
            ), WATCHDOG_DEBUG
        );
        try {
            return $this->twig->loadTemplate( $path );
        } catch ( \Twig_Error_Loader $ex ) {
            // File does not exist.  pass
        }
        return null;
    }
}

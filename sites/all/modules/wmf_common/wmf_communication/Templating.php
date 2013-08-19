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
    protected $template_params;

    function __construct( $templates_dir, $template_name, $language, $template_params ) {
        if ( !function_exists( 'wmf_common_get_twig' ) ) {
            module_load_include( 'inc', 'wmf_common', 'twig' );
        }
        $this->twig = wmf_common_get_twig( $templates_dir );

        $this->templates_dir = $templates_dir;
        $this->template_name = $template_name;
        $this->language = $language;
        $this->template_params = $template_params;
    }

    /**
     * Construct a path from the given parameters, load the appropriate
     * template, and render.
     *
     * For the parameters {template_name: thank_you, language: it, format: txt}, we will look in the path
     * ${templates_dir}/txt/thank_you.it.txt
     *
     * @param string $format 
     *
     * @return string
     */
    function render( $format )
    {
        $template = null;
        $language = $this->language;
        do {
            try {
                $path = "{$format}/{$this->template_name}.{$language}.{$format}";
                watchdog( 'wmf_communication',
                    t( "Attempting to load template from path :path",
                        array( ':path' => $this->templates_dir . "/" . $path )
                    ), WATCHDOG_DEBUG );
                $template = $this->twig->loadTemplate( $path );
                break;
            } catch ( \Twig_Error_Loader $ex ) {
                // pass
            }
            watchdog( 'wmf_communication', t( "Template not found for language ':language', attempting next fallback...", array( ':language' => $language ) ), WATCHDOG_INFO );
            $language = Translation::next_fallback( $language );
        } while ( $language );

        if ( !$template ) {
            throw new Exception( "Cannot load template {$this->template_name} / {$this->language} . {$format}" );
        }

        return $template->render( $this->template_params );
    }
}

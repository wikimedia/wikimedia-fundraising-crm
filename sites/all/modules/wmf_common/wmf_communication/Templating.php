<?php

namespace wmf_communication;

class Templating {
    protected $twig;

    protected $template_name;
    protected $language;
    protected $template_params;

    function __construct( $templates_dir, $template_name, $language, $template_params ) {
        if ( !function_exists( 'wmf_common_get_twig' ) ) {
            module_load_include( 'inc', 'wmf_common', 'twig' );
        }
        $this->twig = wmf_common_get_twig( $templates_dir );

        $this->template_name = $template_name;
        $this->language = $language;
        $this->template_params = $template_params;
    }

    function render( $format )
    {
        $language = $this->language;
        do {
            try {
                $template = $this->twig->loadTemplate( "{$format}/{$this->template_name}.{$language}.{$format}" );
                break;
            } catch ( \Twig_Error_Loader $ex ) {
                // pass
            }
            $language = Translation::next_fallback( $language );
        } while ( $language );

        return $template->render( $this->template_params );
    }
}

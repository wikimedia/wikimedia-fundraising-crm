<?php

namespace wmf_eoy_receipt;

class Templating {
    static protected $twig;

    protected $template_name;
    protected $language;
    protected $template_params;

    function __construct( $templates_dir, $template_name, $language, $template_params ) {
        if ( !self::$twig ) {
            module_load_include( 'inc', 'wmf_common', 'twig' );
            self::$twig = wmf_common_get_twig( $templates_dir );
        }

        $this->template_name = $template_name;
        $this->language = $language;
        $this->template_params = $template_params;
    }

    function render( $format )
    {
        $language = $this->language;
        do {
            try {
                $template = self::$twig->loadTemplate( "{$format}/{$this->template_name}.{$language}.{$format}" );
            } catch ( \Twig_Error_Loader $ex ) {
                $language = Translation::next_fallback( $language );
            }
        } while ( $language && !$template );

        return $template->render( $this->template_params );
    }
}

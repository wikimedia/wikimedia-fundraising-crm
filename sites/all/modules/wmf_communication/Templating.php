<?php namespace wmf_communication;

use \Exception;

use \Twig_Environment;
use \Twig_Error_Loader;
use \Twig_Extension_Sandbox;
use \Twig_Loader_Filesystem;
use \Twig_Loader_String;
use \Twig_Sandbox_SecurityPolicy;

use \Twig_Template;
use \TwigLocalization;

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

    protected static $template_cache = array();

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
        $this->twig = Templating::twig_from_directory( $templates_dir );

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

    static function twig_from_directory( $template_dir ) {
        $loader = new Twig_Loader_Filesystem( $template_dir );
        return Templating::twig_from_loader( $loader );
    }

    static protected function twig_from_loader( $loader ) {
        $cache_dir = drupal_realpath( file_default_scheme() . '://' ) . '/twig/cache';

        $twig = new Twig_Environment( $loader, array(
            'cache' => $cache_dir,
            'auto_reload' => true,
            'charset' => 'utf-8',
        ) );

        $twig->addExtension( new TwigLocalization() );

        $policy = new RestrictiveSecurityPolicy();
        $sandbox = new Twig_Extension_Sandbox( $policy, true );
        $twig->addExtension( $sandbox );

        return $twig;
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
     * @return Twig_Template
     */
    protected function loadTemplate() {
        // We'll cache the result under the first language code we looked for,
        // so we don't need to go through the fallback chain next time.
        $originalLookupCacheKey = $this->getFilePath( $this->language );
        $language = $this->language;
        do {
            $cacheKey = $this->getFilePath( $language );
            if ( array_key_exists( $cacheKey, self::$template_cache ) ) {
                $template = self::$template_cache[$cacheKey];
                // If the language we originally asked for wasn't cached, but
                // the fallback language was, let's cache the template under
                // our original language too so we don't fall back next time.
                if ( $language !== $this->language ) {
                    self::$template_cache[$originalLookupCacheKey] = $template;
                }
                return $template;
            }
            $template = $this->loadTemplateFile( $language );
            if ( $template ) {
                // We have successfully loaded a thing that's not cached yet.
                // Cache the template under its actual language.
                self::$template_cache[$cacheKey] = $template;
                // And if we originally asked for a different language, cache
                // it under that one so we don't have to fall back next time.
                if ( $language !== $this->language ) {
                    self::$template_cache[$originalLookupCacheKey] = $template;
                }
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

        throw new Exception( "No fallbacks for template {$this->template_name}, from {$this->language}" );
    }

    /**
     * Load a Twig template from the filesystem
     *
     * @param string $language
     *
     * @return Twig_Template
     */
    protected function loadTemplateFile( $language ) {
        $path = $this->getRelativeFilePath( $language );

        watchdog( 'wmf_communication',
            "Searching for template file at :path",
            array( ':path' => $path ),
            WATCHDOG_DEBUG
        );
        try {
            return $this->twig->loadTemplate( $path );
        } catch ( Twig_Error_Loader $ex ) {
            // File does not exist.  pass
        }
        return null;
    }

    protected function getFilePath( $language ) {
        return $this->templates_dir . '/' . $this->getRelativeFilePath( $language );
    }

    protected function getRelativeFilePath( $language ) {
        return "{$this->format}/{$this->template_name}.{$language}.{$this->format}";
    }

    /**
     * Evaluate a template passed as string literal
     *
     * TODO: clean up interface
     */
    static function renderStringTemplate( $template, $params ) {
        $loader = new Twig_Loader_String();
        $twig = Templating::twig_from_loader( $loader );

        return $twig->render( $template, $params );
    }
}

class RestrictiveSecurityPolicy extends Twig_Sandbox_SecurityPolicy {
    function __construct() {
        $tags = array(
			'else',
			'endif',
            'if',
			'for',
        );
        $filters = array(
            'escape',
            'l10n_currency',
            'raw',
        );
        $methods = array();
        $properties = array(); // Overridden to allow all
        $functions = array();
        parent::__construct( $tags, $filters, $methods, $properties, $functions );
    }

    function checkPropertyAllowed( $obj, $property ) {
        // pass
    }
}

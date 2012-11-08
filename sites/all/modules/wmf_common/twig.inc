<?php

function wmf_common_get_twig_path()
{
    return variable_get(
        'wmf_common_twig_location',
        drupal_get_path('module', 'wmf_common') . DIRECTORY_SEPARATOR . "Twig"
    );
}

function wmf_common_get_twig( $template_dir )
{
    $twig_path = wmf_common_get_twig_path();
    require_once "{$twig_path}/lib/Twig/Autoloader.php";

    Twig_Autoloader::register();

    $loader = new Twig_Loader_Filesystem( $template_dir );
    $twig = new Twig_Environment( $loader, array(
        'cache' => '/tmp/twig/cache',
        'auto_reload' => true,
        'charset' => 'utf-8',
    ) );
    return $twig;
}

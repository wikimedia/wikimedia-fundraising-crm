<?php

use SmashPig\Core\Context;
use SmashPig\Core\GlobalConfiguration;
use SmashPig\Core\Logging\Logger;
use SmashPig\Core\ProviderConfiguration;

class CRM_SmashPig_ContextWrapper {

  /**
   * Initialize or update a SmashPig Context
   *
   * @param string $logPrefix
   * @param string $provider
   *
   * @return Context
   */
  public static function createContext($logPrefix, $provider = ProviderConfiguration::NO_PROVIDER) {
    // Initialize SmashPig, or set provider configuration if already initialized
    $ctx = Context::get();
    if ($ctx) {
      $globalConfig = $ctx->getGlobalConfiguration();
      $config = ProviderConfiguration::createForProvider($provider, $globalConfig);
      $ctx->setProviderConfiguration($config);
    }
    else {
      $globalConfig = GlobalConfiguration::create();
      $config = ProviderConfiguration::createForProvider($provider, $globalConfig);
      Context::init($globalConfig, $config);
      self::setMessageSource('direct', 'CiviCRM');
      $ctx = Context::get();
    }
    Logger::setPrefix($logPrefix);
    return $ctx;
  }

  static function setMessageSource($type, $name) {
    $ctx = Context::get();
    $ctx->setSourceType($type);
    $ctx->setSourceName($name);
    // FIXME: WMF_specific (hook?)
    $ctx->setVersionFromFile(DRUPAL_ROOT . "/.version-stamp");
  }
}

<?php

namespace Civi\MonoLog;

use Civi\Api4\Entity;
use Civi\Core\LogManager;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\SymfonyMailerHandler;
use Monolog\Handler\TestHandler;
use sgoettsch\monologRotatingFileHandler\Handler\monologRotatingFileHandler;
use Monolog\Logger;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use Monolog\Handler\FirePHPHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class MonologManager {

  /**
   * @var \Monolog\Handler\TestHandler|null
   */
  private static ?TestHandler $testHandlerSingleton;

  /**
   * @return \Monolog\Handler\TestHandler
   */
  public static function testHandlerSingleton(): TestHandler {
    if (!isset(self::$testHandlerSingleton)) {
      self::$testHandlerSingleton = new TestHandler();
    }
    return self::$testHandlerSingleton;
  }

  public static function flush(): void {
    if (isset(self::$testHandlerSingleton)) {
      self::$testHandlerSingleton->clear();
    }
  }

  /**
   * @var array
   */
  private $monologEntities;

  /**
   * @var array
   */
  private $channels = [];

  protected $enabled = TRUE;

  /**
   * Check if the monolog subsystem is enabled.
   *
   * This check helps us avoid errors when there is an attempt
   * to log something (eg. an e-notice or deprecation warning) before
   * the whole system is instantiated.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function isAvailable() {
    return $this->enabled &&
      count(Entity::get(FALSE)->addWhere('name', '=', 'Monolog')->execute());
  }

  /**
   * Mark manager as disabled.
   *
   * During the disabling process we can hit an issue where
   * this is still registered but functions are no longer available.
   */
  public function disable(): void {
    $this->enabled = FALSE;
  }

  /**
   * Find or create a logger.
   *
   * This implementation will look for a service "log.{NAME}". If none is
   * defined, then it will fallback to the "psr_log" service.
   *
   * @param string $channel
   *   Symbolic name of the intended log.
   *   This should correlate to a service "log.{NAME}".
   *
   * @return \Psr\Log\LoggerInterface
   */
  public function getLog($channel = 'default'): LoggerInterface {
    try {
      if (!isset($this->channels[$channel])) {
        if (!class_exists('Monolog\Logger')) {
          throw new \RuntimeException('error triggered before stack fully loaded');
        }
        // Temporarily set the channel to the built in logger
        // to avoid a loop if logging is called while
        // retrieving the monologs.
        $this->channels[$channel] = $this->getBuiltInLogger($channel);
        $monologs = $this->getMonologsByChannel($channel);
        if (empty($monologs)) {
          return $this->getBuiltInLogger($channel);
        }
        $this->channels[$channel] = $this->getLogger($channel);
        $psrProcessor = new PsrLogMessageProcessor();
        $this->channels[$channel]->pushProcessor($psrProcessor);
        foreach ($monologs as $monolog) {
          if ($monolog['type'] === 'syslog') {
            $this->addSyslogLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final']);
          }
          if ($monolog['type'] === 'firephp') {
            $this->addFirePhpLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final']);
          }
          if ($monolog['type'] === 'daily_log') {
            $this->addDailyFileLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final'], $monolog['configuration_options']);
          }
          if ($monolog['type'] === 'log_file') {
            $this->addFileLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final'], $monolog['configuration_options']);
          }
          if ($monolog['type'] === 'std_out') {
            $this->addStdOutLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final']);
          }
          if ($monolog['type'] === 'std_err') {
            $this->addStdErrLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final']);
          }
          if ($monolog['type'] === 'mail') {
            $this->addSymfonyLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final'], $monolog['configuration_options']);
          }
          if ($monolog['type'] === 'test') {
            $this->addTestLogger($channel, $this->channels[$channel], $monolog['minimum_severity'], (bool) $monolog['is_final'], $monolog['configuration_options'] ?? []);
          }
        }
      }
      return $this->channels[$channel];
    }
    catch (\Exception $e) {
      return $this->getBuiltInLogger($channel);
    }
  }

  /**
   * Get configured monologs.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getMonologEntities(): array {
    if (!is_array($this->monologEntities)) {
      $this->monologEntities = (array) \Civi\Api4\Monolog::get(FALSE)->addWhere('is_active', '=', TRUE)->addOrderBy('weight', 'DESC')->execute();
    }
    return $this->monologEntities;
  }

  /**
   * Get the monolog providers to attach to the channel.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getMonologsByChannel($channel): array {
    $return = [];
    if (!$this->isAvailable()) {
      throw new \CRM_Core_Exception('monolog not installed yet');
    }
    foreach ($this->getMonologEntities() as $monolog) {
      if ($monolog['channel'] === $channel || $monolog['channel'] === '*') {
        $return[] = $monolog;
      }
    }
    if (empty($return)) {
      $return[] = $this->getDefaultLogger();
    }
    return $return;
  }

  /**
   * Get the default configured logger.
   *
   * @return array|false
   * @throws \CRM_Core_Exception
   */
  protected function getDefaultLogger() {
    if ($this->enabled) {
      foreach ($this->getMonologEntities() as $monolog) {
        if ($monolog['is_default']) {
          return $monolog;
        }
      }
    }
    return FALSE;
  }

  /**
   * Get the channel name.
   *
   * This version of the name is intended for system wide use so we
   * include civicrm to disambiguation from other potential applications.
   *
   * @param string $channel
   *
   * @return string
   */
  protected function getChannelName(string $channel): string {
    return 'civicrm' . ($channel === 'default' ? '' : '.' . $channel);
  }

  /**
   * @param string $channel
   *
   * @return \Monolog\Logger
   */
  protected function getLogger(string $channel): Logger {
    return new Logger($this->getChannelName($channel));
  }

  /**
   * Add File Logger.
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   * @param $configurationOptions
   *
   * @throws \Exception
   */
  protected function addFileLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal, $configurationOptions): void {
    $logger->pushHandler(new monologRotatingFileHandler($this->getLogFileName($channel), $configurationOptions['max_files'], ($configurationOptions['max_file_size'] * 1024 * 1024), $minimumLevel, !$isFinal));
  }

  /**
   * Add Daily File Logger.
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   * @param $configurationOptions
   */
  protected function addDailyFileLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal, $configurationOptions): void {
    $logger->pushHandler(new RotatingFileHandler($this->getLogFileName($channel), $configurationOptions['max_files'], $minimumLevel, !$isFinal));
  }

  /**
   * Get the log file name & path.
   *
   * This is copied from the CRM_Core_Error class for now.
   *
   * @param string $channel
   *
   * @return string
   */
  protected function getLogFileName(string $channel): string {
    $cacheKey = ($channel === 'default') ? 'logger_file' : 'logger_file' . $channel;
    $prefixString = ($channel === 'default') ? '' : ($channel . '.');

    if (!isset(\Civi::$statics['CRM_Core_Error'][$cacheKey])) {
      $config = \CRM_Core_Config::singleton();

      if (\CRM_Utils_Constant::value('CIVICRM_LOG_HASH', TRUE)) {
        $hash = \CRM_Core_Error::generateLogFileHash($config) . '.';
      }
      else {
        $hash = '';
      }
      $fileName = $config->configAndLogDir . 'CiviCRM.' . $prefixString . $hash . 'log';
      \Civi::$statics['CRM_Core_Error'][$cacheKey] = $fileName;
    }
    return \Civi::$statics['CRM_Core_Error'][$cacheKey];
  }

  /**
   * Add Standard out Logger.
   *
   * This logs to standard out when run from the command line.
   *
   * Note that is supports verbosity flags :
   *
   *  // Drush parameters https://groups.drupal.org/drush/commands
   *  -v  =>  'notice',
   * --verbose' => 'notice',
   * --debug' => 'debug',
   * -d' => 'debug',
   * -q' => 'error',
   * --quiet' => 'error',
   * // https://symfony.com/doc/current/logging/monolog_console.html
   * -vv' => 'info',
   * -vvv' => 'debug',
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   *
   * @noinspection PhpUnusedParameterInspection
   */
  protected function addStdOutLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal): void {
    if (PHP_SAPI === 'cli' && !defined('CIVICRM_TEST')) {
      $minimumLevel = $this->adjustCommandLineMinimumLevel($minimumLevel);
      $formatter = new LineFormatter("%channel%.%level_name%: %message% %extra%\n", NULL, TRUE, TRUE);
      $handler = new StreamHandler('php://stdout', $minimumLevel, !$isFinal);
      $handler->setFormatter($formatter);
      $logger->pushHandler($handler);
    }
  }

  /**
   * Add Standard err Logger.
   *
   * This logs to standard err when run from the command line.
   *
   * Note that is supports verbosity flags :
   *
   *  // Drush parameters https://groups.drupal.org/drush/commands
   *  -v  =>  'notice',
   * --verbose' => 'notice',
   * --debug' => 'debug',
   * -d' => 'debug',
   * -q' => 'error',
   * --quiet' => 'error',
   * // https://symfony.com/doc/current/logging/monolog_console.html
   * -vv' => 'info',
   * -vvv' => 'debug',
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   *
   * @noinspection PhpUnusedParameterInspection
   */
  protected function addStdErrLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal): void {
    if (PHP_SAPI === 'cli' && !defined('CIVICRM_TEST')) {
      $minimumLevel = $this->adjustCommandLineMinimumLevel($minimumLevel);
      $formatter = new LineFormatter("%channel%.%level_name%: %message% %context% %extra%\n", NULL, TRUE, TRUE);
      $handler = new StreamHandler('php://stderr', $minimumLevel, !$isFinal);
      $handler->setFormatter($formatter);
      $logger->pushHandler($handler);
    }
  }

  /**
   * Add mail logger.
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   * @param array $configurationOptions
   *
   * @noinspection PhpUnusedParameterInspection
   */
  protected function addMailLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal, array $configurationOptions): void {
    $handler = new NativeMailerHandler($configurationOptions['to'], $configurationOptions['subject'], $configurationOptions['from'], $minimumLevel, !$isFinal);
    $logger->pushHandler($handler);
  }

  /**
   * Add mail logger.
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   * @param array $configurationOptions
   *
   * @noinspection PhpUnusedParameterInspection
   */
  protected function addSymfonyLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal, array $configurationOptions): void {
    try {
      /* @var \Civi\SymfonyEmail\SymfonyMailProvider $symfonyMailFactory */
      $symfonyMailFactory = \Civi::service('symfony_mail_factory');
      $message = $symfonyMailFactory->getEmail();
      $message->from($configurationOptions['from'] ?: \CRM_Core_BAO_Domain::getFromEmail())
        ->to($configurationOptions['to'])
        ->subject($configurationOptions['subject']);

      $handler = new SymfonyMailerHandler($symfonyMailFactory->getMailer(), $message, $minimumLevel, !$isFinal);
      $formatter = new HtmlFormatter();
      $handler->setFormatter($formatter);
      $logger->pushHandler($handler);
    }
    catch (ServiceNotFoundException $e) {
      $this->addMailLogger($channel, $logger, $minimumLevel, $isFinal, $configurationOptions);
    }
  }

  protected function addTestLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal, array $configurationOptions): void {
    $handler = self::testHandlerSingleton();
    $handler->setBubble(!$isFinal);
    $handler->setLevel($minimumLevel);
    $logger->pushHandler($handler);
  }

  /**
   * Add FirePhp Logger.
   *
   * See https://firephp.org/
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   *
   * @noinspection PhpUnusedParameterInspection
   */
  protected function addFirePhpLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal): void {
    if (\CRM_Core_Permission::check('view debug output')) {
      $logger->pushHandler(new FirePHPHandler($minimumLevel, !$isFinal));
    }
  }

  /**
   * Add Syslog logger.
   *
   * @param string $channel
   * @param \Monolog\Logger $logger
   * @param string $minimumLevel
   * @param bool $isFinal
   */
  protected function addSyslogLogger(string $channel, Logger $logger, string $minimumLevel, bool $isFinal): void {
    $syslog = new SyslogHandler($this->getChannelName($channel), LOG_USER, $minimumLevel, !$isFinal);
    // @todo Use the SyslogFormatter, requires Monolog 3.x
    //https://github.com/Seldaek/monolog/blob/main/src/Monolog/Formatter/SyslogFormatter.php
    $formatter = new LineFormatter("%channel%.%level_name%: %message% %context% %extra%");
    $syslog->setFormatter($formatter);
    $logger->pushHandler($syslog);
  }

  /**
   * @param $channel
   *
   * @return \Psr\Log\LoggerInterface
   */
  protected function getBuiltInLogger($channel): LoggerInterface {
    $manager = new LogManager();
    return $manager->getLog($channel);
  }

  /**
   * Adjust the minimum alert level based on command line arguments.
   *
   * The wordpress handler has this rather nice idea of respecting command
   * line efforts to increase or decrease logging levels.
   *
   * @param string $minimumLevel
   *
   * @return string
   */
  private function adjustCommandLineMinimumLevel(string $minimumLevel): string {
    global $argv;
    $modifiers = [
      // Drush parameters https://groups.drupal.org/drush/commands
      '-v' => 'notice',
      '--verbose' => 'notice',
      '--debug' => 'debug',
      '-d' => 'debug',
      '-q' => 'error',
      '--quiet' => 'error',
      // https://symfony.com/doc/current/logging/monolog_console.html
      '-vv' => 'info',
      '-vvv' => 'debug',
    ];
    foreach ($argv as $argument) {
      if (isset($modifiers[$argument])) {
        $minimumLevel = $modifiers[$argument];
      }
    }
    return $minimumLevel;
  }

}

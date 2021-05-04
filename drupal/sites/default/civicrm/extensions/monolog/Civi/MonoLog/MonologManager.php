<?php
namespace Civi\MonoLog;

use Civi\Core\LogManager;
use sgoettsch\monologRotatingFileHandler\Handler\monologRotatingFileHandler;
use Monolog\Logger;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;
use Monolog\Handler\FirePHPHandler;

class MonologManager {

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
   * @throws \API_Exception
   * @throws \Exception
   */
  public function getLog($channel = 'default'): LoggerInterface {
    if (!$this->enabled) {
      return $this->getBuiltInLogger($channel);
    }
    if (!isset($this->channels[$channel])) {
      $monologs = $this->getMonologsByChannel($channel);
      if (empty($monologs)) {
        return $this->getBuiltInLogger($channel);
      }
      foreach ($monologs as $monolog) {
        $this->channels[$channel] = $this->getLogger($channel);
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
      }
    }
    return $this->channels[$channel];
  }

  /**
   * Get configured monologs.
   *
   * @throws \API_Exception
   */
  protected function getMonologEntities(): array {
    if (!is_array($this->monologEntities)) {
      $this->monologEntities = (array) \Civi\Api4\Monolog::get(FALSE)->addWhere('is_active', '=', TRUE)->addOrderBy('weight')->execute();
    }
    return $this->monologEntities;
  }

  /**
   *
   * @throws \API_Exception
   */
  protected function getMonologsByChannel($channel): array {
    $return = [];
    foreach ($this->getMonologEntities() as $monolog) {
      if ($monolog['channel'] === $channel) {
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
   * @throws \API_Exception
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
    $formatter = new LineFormatter("%channel%.%level_name%: %message% %extra%");
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

}

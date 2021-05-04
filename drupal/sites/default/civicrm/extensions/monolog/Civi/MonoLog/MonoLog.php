<?php
namespace Civi\MonoLog;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MonoLog extends \Psr\Log\AbstractLogger {

  /**
   * \Monolog\Logger logger
   *
   * @todo should this be a singleton? Or should we be extending Logger?
   */
  private $logger;

  /**
   * Constructor
   *
   * @todo Another way of doing this would be to use symfony/monolog-bundle,
   * except then the way to get the logger is via dependency injection in the
   * controller, but Civi doesn't really work that way.
   */
  public function __construct() {
    $this->logger = new Logger('default');

    // @todo everything below should all come from config, just doing proof-of-concept.

    $path = \Civi::settings()->get('monolog_path') ?: \CRM_Core_Config::singleton()->configAndLogDir;
    $path = rtrim($path, '/\\') . "/default.log";

    $handler = new StreamHandler($path, Logger::DEBUG);
    $this->logger->pushHandler($handler);
    // @todo I'm not sure we need the whole message to be formatted, just
    // seeing what this looks like. It's the non-string placeholders that
    // we want formatted, either json or var_dump or other.
    //$handler->setFormatter(new \Monolog\Formatter\JsonFormatter());

    //This built-in processor isn't exactly what I'm looking for, but same
    //concept - replace the placeholders. Can write our own.
    //https://github.com/Seldaek/monolog/blob/master/src/Monolog/Processor/PsrLogMessageProcessor.php
    $this->logger->pushProcessor(new \Monolog\Processor\PsrLogMessageProcessor());

    // @todo could we optionally add the original CRM_Core_Error_Log to the queue so it gets a copy of log messages? I think it would just need a wrapper Handler then we can pushHandler.
  }

  /**
   * Logs with an arbitrary level.
   *
   * @param mixed $level
   * @param string $message
   * @param array $context
   *
   * Note adding 'string' to the type hint in the function declaration causes
   * this to silently crash. It needs to match the parent declaration which
   * unlike the array parameter doesn't have it for some reason.
   */
  public function log($level, $message, array $context = array()) {
    $this->logger->log($level, $message, $context);
  }

}

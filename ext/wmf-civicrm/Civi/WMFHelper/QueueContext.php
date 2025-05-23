<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\WMFHelper;

/**
 *
 * @package Civi
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class QueueContext {

  private static $singleton = NULL;

  /**
   * Stack of contexts.
   *
   * @var array
   */
  private $contexts = [];

  /**
   * @return self
   */
  public static function singleton() {
    if (NULL === self::$singleton) {
      self::$singleton = new self();
    }
    return self::$singleton;
  }

  public function push(array $context): void {
    $this->contexts[] = $context;
    \Civi::log('wmf')->debug($context['action'] . 'starting');
  }

  public function pop() {
    $context = array_pop($this->contexts);
    if ($context) {
      \Civi::log('wmf')->debug($context['action'] . 'Completed');
    }
    return $context ?? [];
  }

}

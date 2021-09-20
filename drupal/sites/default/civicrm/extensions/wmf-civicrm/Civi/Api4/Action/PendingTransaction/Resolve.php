<?php
namespace Civi\Api4\Action\PendingTransaction;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
/**
 * Resolves a pending transaction by completing, canceling or discarding it.
 *
 * This is the action formerly known as 'rectifying' or 'slaying' an 'orphan'.
 *
 * @method $this setMessage(array $msg) Set WMF normalised values.
 * @method array getMessage() Get WMF normalised values.
 *
 * @package Civi\Api4
 */
class Resolve extends AbstractAction {

  /**
   * Associative array of data from SmashPig's pending table.
   *
   * @var array
   */
  protected $message = [];

  public function _run(Result $result) {
    \Civi::Log('wmf')->info(
      'Resolving' . json_encode($this->message)
    );
    // TODO: the actual thing
  }
}

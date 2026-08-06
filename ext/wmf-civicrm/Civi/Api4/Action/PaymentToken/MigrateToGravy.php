<?php

namespace Civi\Api4\Action\PaymentToken;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use CRM_Core_DAO;

/**
 * @method $this setCycleDay(int $cycleDay)
 * @method $this setId(int $id)
 * @method $this setFull(bool $full)
 */
class MigrateToGravy extends AbstractAction {

  /**
   * @var int
   */
  protected $cycleDay;

  /**
   * @var int
   */
  protected $id;

  /**
   * @var bool
   */
  protected $full;

  public function _run(Result $result) {
    $baseScriptDir = __DIR__ . '/../../../../scripts/T418619/T418759/';
    $adyenID = PaymentProcessor::get(FALSE)
      ->addWhere('name', '=', 'adyen')
      ->addWhere('is_test', '=', '0')
      ->setSelect(['id'])
      ->execute()->first()['id'];
    $gravyID = PaymentProcessor::get(FALSE)
      ->addWhere('name', '=', 'gravy')
      ->addWhere('is_test', '=', '0')
      ->setSelect(['id'])
      ->execute()->first()['id'];
    $params = [
      1 => [$adyenID, 'Integer'], 2 => [$gravyID, 'Integer']
    ];
    if ($this->cycleDay) {
      $script = file_get_contents($baseScriptDir . 'migrate_tokens_by_cycle_day.sql');
      $params[3] = [$this->cycleDay, 'Integer'];
    } elseif ($this->id) {
      $script = file_get_contents($baseScriptDir . 'migrate_tokens_by_id.sql');
      $params[3] = [$this->id, 'Integer'];
    } elseif ($this->full) {
      $script = file_get_contents($baseScriptDir . 'migrate_tokens_full.sql');
    } else {
      throw new \RuntimeException("Please specify one of cycleDay, id, or full");
    }
    $result[] =[
      'affected rows' => CRM_Core_DAO::executeQuery($script, $params)->affectedRows()
    ];
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['administer CiviCRM'];
  }
}

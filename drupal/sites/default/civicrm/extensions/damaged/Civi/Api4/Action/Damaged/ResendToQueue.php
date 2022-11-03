<?php
namespace Civi\Api4\Action\Damaged;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Generic\Traits\DAOActionTrait;
use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Utils\CoreUtil;
use CRM_Damaged_DamagedRow;


class ResendToQueue extends AbstractBatchAction {
  use DAOActionTrait;


  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $defaults = $this->getParamDefaults();
    if ($defaults['where'] && $this->where === $defaults['where']) {
      throw new \CRM_Core_Exception('Cannot resend ' . $this->getEntityName() . ' with no "where" parameter specified');
    }

    $items = $this->getBatchRecords();

    if ($this->getCheckPermissions()) {
      $idField = CoreUtil::getIdFieldName($this->getEntityName());
      foreach ($items as $key => $item) {
        // Don't pass the entire item because only the id is a trusted value
        if (!CoreUtil::checkAccessRecord($this, [$idField => $item[$idField]], \CRM_Core_Session::getLoggedInContactID() ?: 0)) {
          throw new UnauthorizedException("ACL check failed");
        }
        $items[$key]['check_permissions'] = TRUE;
      }
    }
    if ($items) {
      $result->exchangeArray($this->resendBatchToQueue($items));
    }
  }

  /**
   * @param $items
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function resendBatchToQueue($items): array {
    $idField = CoreUtil::getIdFieldName($this->getEntityName());
    $result = [];
    $baoName = $this->getBaoName();

    foreach ($items as $item) {
      $row = [];
      $args = array($item, &$row);
      $damagedRow = null;
      try {
        call_user_func_array([$baoName, 'retrieve'], $args);
        $damagedRow = new CRM_Damaged_DamagedRow($row);
        call_user_func([$baoName, 'pushObjectsToQueue'], $damagedRow);
        $bao = call_user_func([$baoName, 'del'], $damagedRow->getId());
      }
      catch (\Error $exception ) {
        throw new \CRM_Core_Exception("Could not resend {$this->getEntityName()} $idField $item[$idField]: {$exception->getMessage()}");
      }
      if ($bao !== FALSE) {
        $result[] = [$idField => $damagedRow->getId()];
      }
      else {
        throw new \CRM_Core_Exception("Could not resend {$this->getEntityName()} $idField $item[$idField]");
      }
    }

    return $result;
  }

}

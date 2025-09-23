<?php
namespace Civi\Api4\Action\Omniactivity;

use Civi\Api4\Action\Omniaction;
use Civi\Api4\Generic\Result;

/**
 *  Class Check.
 *
 * Provided by the  extension.
 *
 * @method $this setType(bool $array)
 * @method array getType()
 *
 * @package Civi\Api4
 */
class Get extends Omniaction {

  /**
   * Types of activities to get - from snooze, remind_me_later, opt_out, unsubscribe.
   *
   * @var array
   */
  protected array $type = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $omniObject = new \CRM_Omnimail_Omniactivity([
      'mail_provider' => $this->getMailProvider(),
    ]);
    $rows = $omniObject->getResult([
      'client' => $this->getClient(),
      'mail_provider' => $this->getMailProvider(),
      'database_id' => $this->getDatabaseID(),
      'check_permissions' => $this->getCheckPermissions(),
      'type' => $this->getType(),
      'start_date' => $this->start,
      'end_date' => $this->end,
      'limit' => $this->limit,
    ]);
    foreach ($rows as $row) {
      $result[] = $row;
    }
  }

  /**
   * @return array[]
   */
  public function fields(): array {
    return parent::fields() + [
      ['name' => 'start', 'type' => 'datetime'],
      ['name' => 'end', 'type' => 'datetime'],
    ];
  }

}

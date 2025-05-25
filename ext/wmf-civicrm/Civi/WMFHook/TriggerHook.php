<?php

namespace Civi\WMFHook;

abstract class TriggerHook {

  /**
   * @var string
   */
  protected $tableName;

  /**
   * @param string|null $tableName
   *
   * @return static
   */
  public function setTableName( ?string $tableName ): TriggerHook {
    $this->tableName = $tableName;
    return $this;
  }

  /**
   * Get the table name.
   *
   * this is set if the info function has requested only one table name.
   *
   * @return string|null
   */
  public function getTableName(): ?string {
    return $this->tableName;
  }

  abstract public function triggerInfo(): array;
}
